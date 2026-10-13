import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { setImmediate } from 'node:timers/promises';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import React, { act } from 'react';
import { create, type ReactTestRenderer } from 'react-test-renderer';
import ts from 'typescript';
import { ApiError } from '../src/core/api';
import * as offline from '../src/core/offline';
import type { AccountState, Bootstrap, CashSession, Credentials, OfflineSalePayload, Receipt, Snapshot, Store } from '../src/core/types';
import type { DeviceSession, SessionVault } from '../src/storage/shared';
import * as actionVerification from '../src/runtime/actionVerification';
import type { useMobile } from '../src/runtime/useMobile';

(globalThis as typeof globalThis & { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
const API = 'https://mobile-runtime.example.test/api/v1';
const copy = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;
type Runtime = ReturnType<typeof useMobile>;

class AccountStore implements Store {
  readonly rows = new Map<string, AccountState>();
  failNextReadAfterEnqueue = false;
  failNextUpdate = false;
  failNextInitializationCommit = false;
  private failNextRead = false;
  private pending: Promise<unknown> = Promise.resolve();
  async read(owner: string): Promise<AccountState | null> {
    await this.pending.catch(() => undefined);
    if (this.failNextRead) { this.failNextRead = false; throw new Error('Device read failed after the cash sale was saved.'); }
    return copy(this.rows.get(owner) ?? null);
  }
  update(owner: string, change: (state: AccountState) => AccountState): Promise<AccountState> {
    const task = this.pending.catch(() => undefined).then(() => {
      const previousQueueCount = this.rows.get(owner)?.queue.length ?? 0;
      const next = change(copy(this.rows.get(owner) ?? offline.emptyState(owner)));
      assert.equal(next.owner, owner);
      if (this.failNextUpdate || (this.failNextInitializationCommit && next.bootstrap
        && (this.rows.get(owner)?.initializingCredentialHashes?.length ?? 0) > 0)) {
        this.failNextUpdate = false; this.failNextInitializationCommit = false;
        throw new Error('Encrypted account initialization failed.');
      }
      this.rows.set(owner, copy(next));
      if (this.failNextReadAfterEnqueue && next.queue.length > previousQueueCount) {
        this.failNextReadAfterEnqueue = false; this.failNextRead = true;
      }
      return copy(next);
    });
    this.pending = task;
    return task;
  }
}

/** Mount the real hook; replace platform I/O, without replacing its offline engine or React state. */
async function runtime() {
  const store = new AccountStore();
  const control = { now: Date.now(), connected: true, snapshotCalls: 0, bootstrapCalls: 0,
    bootstrapFailure: null as ApiError | null, mutationFailure: null as ApiError | null,
    failRefreshAfterMutation: false, failVaultRemove: false, failVaultWrite: null as 'before' | 'after' | null,
    failVaultReadAfterWrite: false, ignoreNextVaultWrite: false, snapshotFailure: null as ApiError | null,
    revokeFailure: null as ApiError | null, issuedTokens: [] as string[], revocationAttempts: [] as string[],
    vaultWrites: [] as DeviceSession[], stock: new Map([[1, 20], [2, 20]]),
    mutations: [] as { path: string; payload: Record<string, unknown> }[], sales: [] as OfflineSalePayload[] };
  let vaultValue: DeviceSession | null = null;
  let identifier = 0;
  let failNextVaultRead = false;
  let networkListener: ((value: { isConnected: boolean; isInternetReachable: boolean }) => void) | null = null;
  let foregroundListener: ((value: string) => void) | null = null;
  const intervals = new Set<() => void>();
  const appState = { currentState: 'active', addEventListener: (_name: string, listener: (value: string) => void) => {
    foregroundListener = listener; return { remove: () => { foregroundListener = null; } };
  } };
  const vault: SessionVault = {
    read: async () => {
      if (failNextVaultRead) { failNextVaultRead = false; throw new Error('Device credential readback failed.'); }
      return copy(vaultValue);
    }, write: async value => {
      control.vaultWrites.push(copy(value));
      const fail = control.failVaultWrite; control.failVaultWrite = null;
      if (fail === 'before') { throw new Error('Device credential persistence failed.'); }
      if (control.ignoreNextVaultWrite) { control.ignoreNextVaultWrite = false; return; }
      vaultValue = copy(value);
      if (control.failVaultReadAfterWrite) { control.failVaultReadAfterWrite = false; failNextVaultRead = true; }
      if (fail === 'after') { throw new Error('Device credential persistence failed.'); }
    }, remove: async () => {
      if (control.failVaultRemove) { throw new Error('The private device credential could not be removed.'); }
      vaultValue = null;
    },
  };
  const accountId = (token?: string | null) => token?.includes('other') ? 2 : 1;
  const bootstrap = (id: number): Bootstrap => ({ api_version: 1, server_time: new Date(control.now).toISOString(),
    user: { id, name: `Staff ${id}`, email: id === 1 ? 'admin@example.test' : 'other@example.test', role: 'admin', is_active: true },
    tenant: { id, name: id === 1 ? 'Original company' : 'Other company' },
    abilities: ['account:read', 'catalog:read', 'sales:write', 'inventory:write', 'cash:write'],
    locations: [{ id, name: `Shop ${id}`, type: 'store' }],
    settings: { currency: 'PHP', tax_percent: 12, receipt_footer: 'Thank you', receipt_logo_url: null },
    access: { can_write: true, operations_paused: false },
    offline: { cash_sales_only: true, requires_online_confirmation: true, snapshot_url: '/api/v1/offline/snapshot', sales_url: '/api/v1/offline/sales' } });
  const snapshot = (id: number): Snapshot => ({ version: 1, captured_at: new Date(control.now).toISOString(),
    expires_at: new Date(control.now + 86400000).toISOString(), permit: `signed-permit-${id}-${control.snapshotCalls}`,
    user: { id, role: 'admin' }, tenant: bootstrap(id).tenant, locations: bootstrap(id).locations,
    products: [{ id, name: `Shirt ${id}`, sku: `SHIRT-${id}`, barcode: `BARCODE-${id}`, category: 'Clothing', price: 100, wholesale_price: 80,
      reorder_level: 1, color: null, variant: 'Medium / Blue', product_family_id: id, family_name: 'Shirt', variant_options: { size: 'M', color: 'Blue' },
      stocks: [{ location_id: id, quantity: control.stock.get(id)!, on_hand: control.stock.get(id)!, reserved_quantity: 0, available_quantity: control.stock.get(id)! }] }],
    settings: { tax_percent: 12, receipt_footer: 'Thank you' }, cash_sessions: [{ id, location_id: id }], can_write: true });
  const shift = (id: number): CashSession => ({ id, location_id: id, status: 'open', cashier_name: `Staff ${id}`,
    opened_at: new Date(control.now).toISOString(), closed_at: null, opening_float: 500, cash_sales: 0, cash_in: 0, cash_out: 0,
    expected_cash: 500, counted_cash: null, variance: null, close_reason: null, adjustments: [] });
  const connected = () => { if (!control.connected) { throw new ApiError('The test connection is unavailable.'); } };

  class Client {
    readonly baseURL = API;
    readonly account: number;
    readonly token: string | null;
    constructor(_server: string, options: { token?: string | null } = {}) { this.account = accountId(options.token); this.token = options.token ?? null; }
    async authToken(credentials: Credentials) {
      connected();
      const token = `${credentials.email === 'other@example.test' ? 'test-other-token' : 'test-original-token'}-${control.issuedTokens.length + 1}`;
      control.issuedTokens.push(token);
      return { token_type: 'Bearer', token,
        expires_at: new Date(control.now + 30 * 86400000).toISOString(), abilities: bootstrap(this.account).abilities };
    }
    async bootstrap() {
      connected(); control.bootstrapCalls++;
      if (control.bootstrapFailure) { throw control.bootstrapFailure; }
      return copy(bootstrap(this.account));
    }
    async snapshot() {
      connected(); control.snapshotCalls++; control.now += 2000;
      if (control.snapshotFailure) { throw control.snapshotFailure; }
      return copy(snapshot(this.account));
    }
    async getCashSessions() { connected(); return { current: shift(this.account), history: [] }; }
    async revoke() { control.revocationAttempts.push(this.token!); connected(); if (control.revokeFailure) { throw control.revokeFailure; } }
    async devices() { connected(); return { data: [] }; }
    async revokeDevice() { connected(); }
    async request(path: string, _method: string, payload: Record<string, unknown>) {
      connected(); control.mutations.push({ path, payload: copy(payload) });
      if (control.mutationFailure) { throw control.mutationFailure; }
      assert.equal(path, '/stock/adjust');
      control.stock.set(this.account, control.stock.get(this.account)! + Number(payload.quantity));
      const response = snapshot(this.account).products[0];
      if (control.failRefreshAfterMutation) { control.bootstrapFailure = new ApiError('Catalog refresh failed after confirmation.', 503); }
      return copy(response);
    }
    async createOfflineSale(payload: OfflineSalePayload): Promise<Receipt> {
      connected(); control.sales.push(copy(payload));
      control.stock.set(this.account, control.stock.get(this.account)! - payload.items.reduce((sum, item) => sum + item.quantity, 0));
      const expected = payload.expected;
      return { id: control.sales.length, number: `SR-TEST-${control.sales.length}`, location_id: payload.location_id, customer_id: null,
        status: 'completed', price_tier: payload.price_tier, cash_session_id: expected.cash_session_id, source: 'pos', simulated: false,
        channel_id: null, channel_name: null, external_reference: null, returned_amount: 0, net_total: expected.total_cents / 100,
        cashier_name: `Staff ${this.account}`, discount_percent: payload.discount_percent, tax_percent: payload.tax_percent,
        created_at: new Date(control.now).toISOString(), offline: { recorded_at: payload.recorded_at, synced_at: new Date(control.now).toISOString() },
        returned_at: null, returns: [], payments: copy(payload.payments),
        items: payload.items.map(item => ({ ...item, name: 'Shirt', sku: 'SHIRT-1', unit_price: 100, line_total: item.quantity * 100,
          discount: 0, tax: item.quantity * 12, total: item.quantity * 112, returned_quantity: 0, returnable_quantity: item.quantity,
          returnable_amount: item.quantity * 112, returned_amount: 0 })),
        subtotal: expected.subtotal_cents / 100, discount: expected.discount_cents / 100, tax: expected.tax_cents / 100,
        total: expected.total_cents / 100, paid: payload.payments[0].amount, change: payload.payments[0].amount - expected.total_cents / 100 };
    }
    async createSale(): Promise<never> { throw new Error('Runtime cash POS must use its durable outbox.'); }
  }
  class ClockDate extends Date { static now() { return control.now; } }
  class ClockEngine extends offline.OfflineEngine {
    constructor(storage: Store, options: offline.OfflineEngineOptions) { super(storage, { ...options, now: () => control.now }); }
  }
  const modules: Record<string, unknown> = {
    react: React, 'react-native': { AppState: appState, Platform: { OS: 'ios' } },
    'expo-crypto': { randomUUID: () => `runtime-request-${++identifier}`, CryptoDigestAlgorithm: { SHA256: 'sha256' },
      digestStringAsync: async (_algorithm: string, value: string) => createHash('sha256').update(value).digest('hex') },
    'expo-network': { addNetworkStateListener: (listener: typeof networkListener) => { networkListener = listener; return { remove: () => { networkListener = null; } }; } },
    '../core/api': { ApiClient: Client, ApiError }, '../core/offline': { ...offline, OfflineEngine: ClockEngine },
    '../storage/store': { accountStore: store }, '../storage/vault': { sessionVault: vault },
    '../config': { apiUrl: API, isolated: true, assertClientEnvironment: () => undefined },
    './receiptBranding': { isReceiptLogoDataUri: () => false, loadReceiptBranding: async () => undefined },
    './actionVerification': actionVerification,
    './transport': { appFetch: () => { throw new Error('Hook tests cannot make network requests.'); } },
  };
  const source = readFileSync(new URL('../src/runtime/useMobile.ts', import.meta.url), 'utf8');
  const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  const output: { useMobile?: typeof useMobile } = {};
  runInNewContext(compiled, { exports: output, Date: ClockDate, Error, Promise, console,
    setInterval: (callback: () => void) => { intervals.add(callback); return callback; }, clearInterval: (callback: () => void) => intervals.delete(callback),
    require: (name: string) => { if (!(name in modules)) { throw new Error(`Unexpected hook dependency: ${name}`); } return modules[name]; } });
  assert.ok(output.useMobile);
  let current: Runtime;
  let renderer: ReactTestRenderer;
  const navigate = () => undefined;
  function Probe() { current = output.useMobile!('inventory', navigate); return React.createElement('output', null, current.model.phase); }
  const dispatch = async (operation: () => void) => {
    await act(async () => { operation(); await setImmediate(); });
    await act(async () => { await setImmediate(); });
  };
  await act(async () => { renderer = create(React.createElement(Probe)); await setImmediate(); });
  await dispatch(() => undefined);
  return { control, store, get current() { return current!; }, dispatch,
    get storedSession() { return copy(vaultValue); },
    forgetCredential: () => { vaultValue = null; },
    login: async (email = 'admin@example.test') => {
      await dispatch(() => { current.actions.onLoginField('email', email); current.actions.onLoginField('password', 'fictitious-password'); });
      await dispatch(() => current.actions.onLogin());
      assert.equal(current.model.phase, 'workspace'); assert.equal(current.model.busy, false);
    },
    network: async (value: boolean) => { control.connected = value; await dispatch(() => networkListener?.({ isConnected: value, isInternetReachable: value })); },
    foreground: async () => { appState.currentState = 'active'; await dispatch(() => foregroundListener?.('active')); },
    tick: async () => { await dispatch(() => intervals.forEach(callback => callback())); },
    restart: async () => {
      await act(async () => { renderer!.unmount(); await setImmediate(); });
      await act(async () => { renderer = create(React.createElement(Probe)); await setImmediate(); });
      await dispatch(() => undefined);
    },
    close: async () => { await act(async () => { renderer!.unmount(); await setImmediate(); }); },
  };
}

test('first saved catalog is usable immediately after sign-in without waiting for an expiry timer', async () => {
  const app = await runtime();
  try {
    await app.login();

    assert.equal(app.current.model.catalog.usable, true);
    assert.equal(app.current.model.products[0].stock, 20);
    await app.dispatch(() => app.current.actions.onAddProduct(1));
    assert.equal(app.current.model.cart.length, 1);
    assert.equal(app.current.model.checkoutAllowed, true);
  } finally { await app.close(); }
});

test('foreground and network reconnection retain a reviewed cart until staff complete or clear it', async () => {
  const app = await runtime();
  try {
    await app.login();
    await app.dispatch(() => app.current.actions.onAddProduct(1));
    await app.dispatch(() => app.current.actions.onCashReceivedChange('112'));
    await app.dispatch(() => app.current.actions.onReview());
    const catalogCalls = app.control.snapshotCalls;

    await app.network(false); await app.network(true); await app.foreground(); await app.tick();

    assert.equal(app.current.model.reviewOpen, true);
    assert.equal(app.current.model.cart[0].quantity, 1);
    assert.equal(app.current.model.totals?.totalCents, 11200);
    assert.equal(app.control.snapshotCalls, catalogCalls, 'Automatic refresh cannot replace a reviewed catalog.');
    await app.dispatch(() => app.current.actions.onClearCart());
    await app.foreground();
    assert.equal(app.current.model.cart.length, 0);
    assert.ok(app.control.snapshotCalls > catalogCalls);
  } finally { await app.close(); }
});

test('a confirmed stock update remains confirmed when its subsequent catalog refresh fails', async () => {
  const app = await runtime();
  try {
    await app.login();
    await app.dispatch(() => app.current.actions.onAdjustProduct(1));
    await app.dispatch(() => { app.current.actions.onAdjustmentField('quantity', '2'); app.current.actions.onAdjustmentField('reason', 'Count correction'); });
    app.control.failRefreshAfterMutation = true;

    await app.dispatch(() => app.current.actions.onSubmitAdjustment());

    assert.equal(app.control.mutations.length, 1);
    assert.equal(app.current.model.pendingUpdate, null);
    assert.match(app.current.model.notice!, /update is confirmed/i);
    assert.equal((await app.store.read(app.current.session!.owner))?.pendingAction, undefined);
    await app.dispatch(() => app.current.actions.onSync());
    assert.equal(app.control.mutations.length, 1, 'Refreshing cannot reissue an already confirmed stock write.');
    app.control.bootstrapFailure = null; app.control.failRefreshAfterMutation = false;
    await app.dispatch(() => app.current.actions.onSync());
    assert.equal(app.current.model.products[0].stock, 22);
    assert.equal(app.control.mutations.length, 1);
  } finally { await app.close(); }
});

test('an uncertain original stock update cannot be retried or replaced by submitting another adjustment form', async () => {
  const app = await runtime();
  try {
    await app.login();
    await app.dispatch(() => app.current.actions.onAdjustProduct(1));
    await app.dispatch(() => { app.current.actions.onAdjustmentField('quantity', '2'); app.current.actions.onAdjustmentField('reason', 'Original correction'); });
    app.control.mutationFailure = new ApiError('The original update needs confirmation.');
    await app.dispatch(() => app.current.actions.onSubmitAdjustment());
    const original = copy((await app.store.read(app.current.session!.owner))!.pendingAction);

    await app.network(true);
    await app.dispatch(() => { app.current.actions.onAdjustmentField('quantity', '99'); app.current.actions.onAdjustmentField('reason', 'New correction'); });
    await app.dispatch(() => app.current.actions.onSubmitAdjustment());

    assert.equal(app.control.mutations.length, 1, 'A new form cannot silently retry the earlier update.');
    assert.deepEqual((await app.store.read(app.current.session!.owner))!.pendingAction, original);
    assert.match(app.current.model.error!, /earlier update/i);
    app.control.mutationFailure = null;
    await app.dispatch(() => app.current.actions.onSync());
    assert.equal(app.control.mutations.length, 2);
    assert.deepEqual(app.control.mutations[1].payload, app.control.mutations[0].payload);
    assert.equal(app.current.model.pendingUpdate, null);
    assert.equal(app.current.model.products[0].stock, 22);
  } finally { await app.close(); }
});

test('offline cash survives sign-out, remains hidden from another company, and recovers under its original account', async () => {
  const app = await runtime();
  try {
    await app.login();
    const owner = app.current.session!.owner;
    await app.network(false);
    await app.dispatch(() => app.current.actions.onAddProduct(1));
    await app.dispatch(() => app.current.actions.onCashReceivedChange('112'));
    await app.dispatch(() => app.current.actions.onReview());
    await app.dispatch(() => app.current.actions.onConfirmSale());
    const entry = copy((await app.store.read(owner))!.queue[0]);
    assert.equal(app.current.model.pendingCount, 1);
    assert.equal(app.control.sales.length, 0);

    await app.dispatch(() => app.current.actions.onSignOut());
    assert.equal(app.current.model.phase, 'login');
    assert.equal(copy(app.current.model).account, null);
    assert.equal((await app.store.read(owner))?.snapshot, null);
    assert.deepEqual((await app.store.read(owner))?.queue[0].payload, entry.payload);
    app.control.connected = true;
    await app.login('other@example.test');
    assert.equal(app.current.model.account?.company, 'Other company');
    assert.equal(app.current.model.pendingCount, 0);
    assert.equal(app.current.model.savedSales.length, 0);
    assert.deepEqual((await app.store.read(owner))?.queue[0].payload, entry.payload);
    await app.dispatch(() => app.current.actions.onSignOut());
    await app.login();
    assert.equal(app.current.model.account?.company, 'Original company');
    assert.equal(app.current.model.savedSales[0].status, 'confirmed');
    assert.equal(app.current.model.pendingCount, 0);
    assert.deepEqual(app.control.sales[0], entry.payload);
    assert.equal(app.control.sales.length, 1);
  } finally { await app.close(); }
});

test('a saved cash sale clears its reviewed cart even when its first recovery read fails', async () => {
  const app = await runtime();
  try {
    await app.login();
    const owner = app.current.session!.owner;
    await app.network(false);
    await app.dispatch(() => app.current.actions.onAddProduct(1));
    await app.dispatch(() => app.current.actions.onCashReceivedChange('112'));
    await app.dispatch(() => app.current.actions.onReview());
    app.store.failNextReadAfterEnqueue = true;

    await app.dispatch(() => app.current.actions.onConfirmSale());

    const original = copy((await app.store.read(owner))!.queue[0]);
    assert.equal(app.current.model.cart.length, 0);
    assert.equal(app.current.model.reviewOpen, false);
    assert.equal(app.current.model.cashReceived, '');
    assert.equal(app.current.model.pendingCount, 1);
    assert.equal(app.current.model.receipt?.number, original.provisionalNumber);
    assert.equal(app.current.model.products[0].stock, 19);
    assert.match(app.current.model.notice!, /cash sale is saved/i);
    assert.match(app.current.model.error!, /read failed after the cash sale was saved/i);
    await app.dispatch(() => app.current.actions.onConfirmSale());
    await app.dispatch(() => app.current.actions.onReview());
    assert.equal((await app.store.read(owner))!.queue.length, 1);
    assert.deepEqual((await app.store.read(owner))!.queue[0].payload, original.payload);
    assert.equal(app.control.sales.length, 0);
    await app.network(true);
    assert.equal(app.current.model.pendingCount, 0);
    assert.equal(app.control.sales.length, 1);
    assert.deepEqual(app.control.sales[0], original.payload);
  } finally { await app.close(); }
});

test('credential deletion failure after persisted sign-out still locks company memory and cannot reactivate on cold start', async () => {
  const app = await runtime();
  try {
    await app.login();
    const owner = app.current.session!.owner;
    await app.network(false);
    await app.dispatch(() => app.current.actions.onAddProduct(1));
    await app.dispatch(() => app.current.actions.onCashReceivedChange('112'));
    await app.dispatch(() => app.current.actions.onReview());
    await app.dispatch(() => app.current.actions.onConfirmSale());
    const original = copy((await app.store.read(owner))!.queue[0]);
    app.control.failVaultRemove = true;

    await app.dispatch(() => app.current.actions.onSignOut());

    assert.equal(app.current.model.phase, 'login');
    assert.equal(app.current.session, null);
    assert.equal(app.current.account, null);
    assert.equal(app.current.model.account, null);
    assert.equal(app.current.model.products.length, 0);
    assert.equal(app.current.model.savedSales.length, 0);
    assert.equal((await app.store.read(owner))!.bootstrap, null);
    assert.equal((await app.store.read(owner))!.snapshot, null);
    assert.deepEqual((await app.store.read(owner))!.queue[0].payload, original.payload);
    assert.equal(app.storedSession!.owner, owner, 'A retained keychain token cannot restore the explicitly wiped account.');
    await app.restart();
    assert.equal(app.current.model.phase, 'login');
    assert.equal(app.current.session, null);
    const calls = app.control.bootstrapCalls;
    await app.network(true); await app.foreground(); await app.tick();
    assert.equal(app.control.bootstrapCalls, calls, 'Reconnect must not use a credential left after explicit sign-out.');
    assert.equal(app.current.model.phase, 'login');
    assert.deepEqual((await app.store.read(owner))!.queue[0].payload, original.payload);
    app.control.failVaultRemove = false;
    await app.login();
    assert.equal(app.control.sales.length, 1);
    assert.deepEqual(app.control.sales[0], original.payload);
  } finally { await app.close(); }
});

async function attemptLogin(app: Awaited<ReturnType<typeof runtime>>, email = 'admin@example.test') {
  await app.dispatch(() => { app.current.actions.onLoginField('email', email); app.current.actions.onLoginField('password', 'fictitious-password'); });
  await app.dispatch(() => app.current.actions.onLogin());
}

async function queuedOriginal(app: Awaited<ReturnType<typeof runtime>>) {
  await app.login();
  const owner = app.current.session!.owner;
  await app.network(false);
  await app.dispatch(() => app.current.actions.onAddProduct(1));
  await app.dispatch(() => app.current.actions.onCashReceivedChange('112'));
  await app.dispatch(() => app.current.actions.onReview());
  await app.dispatch(() => app.current.actions.onConfirmSale());
  return { owner, original: copy((await app.store.read(owner))!), credential: app.storedSession! };
}

test('bootstrap failure revokes only the newly issued token and leaves prior company credentials and queued cash intact', async () => {
  const app = await runtime();
  try {
    const { owner, original, credential } = await queuedOriginal(app);
    app.control.connected = true;
    await app.dispatch(() => app.current.actions.onRequireLogin());
    app.control.bootstrapFailure = new ApiError('New sign-in bootstrap was rejected.', 401);

    await attemptLogin(app, 'other@example.test');

    assert.deepEqual(app.control.revocationAttempts, [app.control.issuedTokens[1]]);
    assert.notEqual(app.control.revocationAttempts[0], credential.token);
    assert.deepEqual(app.storedSession, credential);
    assert.deepEqual(await app.store.read(owner), original);
    assert.equal(app.current.session!.owner, owner);
    assert.equal(app.current.model.phase, 'login');
    assert.equal(app.current.model.error, 'New sign-in bootstrap was rejected.');
    assert.equal(app.control.sales.length, 0);
  } finally { await app.close(); }
});

test('encrypted account initialization failure revokes the new token before attempting credential persistence', async () => {
  const app = await runtime();
  try {
    app.store.failNextUpdate = true;
    await attemptLogin(app);
    assert.deepEqual(app.control.revocationAttempts, [app.control.issuedTokens[0]]);
    assert.equal(app.control.vaultWrites.length, 0);
    assert.equal(app.storedSession, null);
    assert.equal(app.current.session, null);
    assert.equal(app.current.model.phase, 'login');
    assert.equal(app.current.model.error, 'Encrypted account initialization failed.');
  } finally { await app.close(); }
});

test('ambiguous credential-write failure restores the prior verified credential and retains the original outbox and bootstrap', async () => {
  const app = await runtime();
  try {
    const { owner, original, credential } = await queuedOriginal(app);
    app.control.connected = true;
    await app.dispatch(() => app.current.actions.onRequireLogin());
    app.control.failVaultWrite = 'after';

    await attemptLogin(app);

    assert.deepEqual(app.control.revocationAttempts, [app.control.issuedTokens[1]]);
    assert.deepEqual(app.storedSession, credential);
    assert.deepEqual(await app.store.read(owner), original);
    assert.equal(app.current.session!.token, credential.token);
    assert.equal(app.current.model.phase, 'login');
    assert.equal(app.current.model.error, 'Device credential persistence failed.');
    assert.equal(app.control.sales.length, 0);
  } finally { await app.close(); }
});

test('credential readback and final local initialization must succeed before a new login becomes active', async () => {
  for (const fault of ['readback', 'commit', 'ignored-write'] as const) {
    const app = await runtime();
    try {
      if (fault === 'readback') { app.control.failVaultReadAfterWrite = true; }
      if (fault === 'commit') { app.store.failNextInitializationCommit = true; }
      if (fault === 'ignored-write') { app.control.ignoreNextVaultWrite = true; }

      await attemptLogin(app);

      assert.deepEqual(app.control.revocationAttempts, [app.control.issuedTokens[0]], fault);
      assert.equal(app.storedSession, null, fault);
      assert.equal(app.current.session, null, fault);
      assert.equal(app.current.model.phase, 'login', fault);
      assert.equal(app.current.model.account, null, fault);
      assert.equal((await app.store.read(offline.ownerFor(API, 1, 1)))?.bootstrap, null, fault);
    } finally { await app.close(); }
  }
});

test('a credential left by failed setup cannot restore access or send queued cash when cleanup is unavailable', async () => {
  const app = await runtime();
  try {
    const { owner, original } = await queuedOriginal(app);
    app.forgetCredential();
    app.control.connected = true;
    await app.dispatch(() => app.current.actions.onRequireLogin());
    app.control.failVaultWrite = 'after';
    app.control.failVaultRemove = true;
    app.control.revokeFailure = new ApiError('Revocation temporarily unavailable.', 503);

    await attemptLogin(app);

    assert.equal(app.current.model.error, 'Device credential persistence failed.', 'Cleanup cannot replace the original failure.');
    const token = app.control.issuedTokens[1];
    assert.equal(app.storedSession!.token, token);
    const saved = (await app.store.read(owner))!;
    assert.deepEqual(saved.bootstrap, original.bootstrap);
    assert.deepEqual(saved.queue, original.queue);
    assert.ok(saved.initializingCredentialHashes!.includes(createHash('sha256').update(token).digest('hex')));
    assert.ok(!JSON.stringify(saved).includes(token), 'Bearer credentials remain outside account storage.');

    await app.restart();
    assert.equal(app.current.model.phase, 'login');
    assert.equal(app.current.session, null);
    assert.equal(app.current.account, null);
    const bootstrapCalls = app.control.bootstrapCalls;
    await app.network(true); await app.foreground(); await app.tick();
    assert.equal(app.control.bootstrapCalls, bootstrapCalls);
    assert.equal(app.control.sales.length, 0);
    assert.deepEqual((await app.store.read(owner))!.queue, original.queue);
  } finally { await app.close(); }
});

test('failed sign-in setup never restores a credential whose account was explicitly wiped by sign-out', async () => {
  const app = await runtime();
  try {
    const { owner, original, credential } = await queuedOriginal(app);
    app.control.failVaultRemove = true;
    await app.dispatch(() => app.current.actions.onSignOut());
    assert.equal((await app.store.read(owner))!.bootstrap, null);
    const writesBeforeRetry = app.control.vaultWrites.length;
    app.control.connected = true;
    app.control.failVaultWrite = 'after';

    await attemptLogin(app);

    assert.equal(app.current.session, null);
    assert.equal(app.current.model.phase, 'login');
    assert.equal((await app.store.read(owner))!.bootstrap, null);
    assert.deepEqual((await app.store.read(owner))!.queue, original.queue);
    assert.ok(app.control.vaultWrites.slice(writesBeforeRetry).every(value => value.token !== credential.token));
    await app.restart();
    assert.equal(app.current.session, null);
    assert.equal(app.current.model.phase, 'login');
  } finally { await app.close(); }
});

test('catalog refresh failure after verified local sign-in retains the established device token', async () => {
  const app = await runtime();
  try {
    app.control.snapshotFailure = new ApiError('Saved catalog refresh is temporarily unavailable.', 503);
    await attemptLogin(app);
    assert.equal(app.current.model.phase, 'workspace');
    assert.equal(app.current.session!.token, app.control.issuedTokens[0]);
    assert.equal(app.storedSession!.token, app.control.issuedTokens[0]);
    assert.equal(app.current.model.error, 'Saved catalog refresh is temporarily unavailable.');
    assert.deepEqual(app.control.revocationAttempts, []);
    const owner = app.current.session!.owner;
    assert.deepEqual((await app.store.read(owner))!.initializingCredentialHashes, []);
    app.control.snapshotFailure = null;
    await app.network(true);
    assert.equal(app.current.model.catalog.usable, true);
    assert.equal(app.current.session!.token, app.control.issuedTokens[0]);
    assert.deepEqual(app.control.revocationAttempts, []);
  } finally { await app.close(); }
});
