/// <reference types="node" />
import assert from 'node:assert/strict';
import test from 'node:test';
import { ApiClient, ApiError, normalizeApiUrl } from '../src/core/api';
import { OfflineEngine, available, cents, emptyState, isSnapshotFresh, ownerFor, percentage, quote, remainingEntries } from '../src/core/offline';
import type { AccountState, Bootstrap, EnqueueInput, MobileClient, OfflineSalePayload, Receipt, SalePayload, Snapshot, Store } from '../src/core/types';

const ORIGIN = 'https://posonly.example.test';
const API = `${ORIGIN}/api/v1`;
const OWNER = ownerFor(API, 1, 1);
const TIME = Date.parse('2026-10-13T08:00:00Z');
const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;

function snapshot(): Snapshot {
  return { version: 1, captured_at: new Date(TIME).toISOString(), expires_at: new Date(TIME + 86400000).toISOString(), permit: 'opaque-signed-permit',
    user: { id: 1, role: 'admin' }, tenant: { id: 1, name: 'Fictitious QA company' },
    locations: [{ id: 1, name: 'Main shop', type: 'retail' }, { id: 2, name: 'Branch', type: 'retail' }],
    products: [{ id: 1, name: 'Test shirt', sku: 'TEST-M-RED', barcode: 'TESTBARCODE', category: 'Clothing', price: 100,
      wholesale_price: 80, reorder_level: 2, color: '#ff0000', variant: 'Medium / Red', product_family_id: 1, family_name: 'Test shirt',
      variant_options: { Size: 'Medium', Color: 'Red' }, stocks: [
        { location_id: 1, quantity: 20, on_hand: 20, reserved_quantity: 2, available_quantity: 18 },
        { location_id: 2, quantity: 7, on_hand: 7, reserved_quantity: 0, available_quantity: 7 },
      ] }], settings: { tax_percent: 12, receipt_footer: 'Thank you.' }, cash_sessions: [{ id: 1, location_id: 1 }], can_write: true };
}

function bootstrap(): Bootstrap {
  return { api_version: 1, server_time: new Date(TIME).toISOString(),
    user: { id: 1, name: 'QA administrator', email: 'qa@example.test', role: 'admin', is_active: true },
    tenant: { id: 1, name: 'Fictitious QA company' }, abilities: ['account:read', 'catalog:read', 'sales:write', 'cash:write'],
    locations: snapshot().locations, settings: { currency: 'PHP', tax_percent: 12, receipt_footer: 'Thank you.' },
    access: { can_write: true, operations_paused: false },
    offline: { cash_sales_only: true, requires_online_confirmation: true, snapshot_url: '/api/v1/offline/snapshot', sales_url: '/api/v1/offline/sales' } };
}

function input(overrides: Partial<EnqueueInput> = {}): EnqueueInput {
  return { locationId: 1, items: [{ product_id: 1, quantity: 2 }], tier: 'retail', discountPercent: 10, cashAmount: 250,
    snapshotPermit: 'opaque-signed-permit', ...overrides };
}

class MemoryStore implements Store {
  states = new Map<string, AccountState>();
  private updates: Promise<unknown> = Promise.resolve();
  constructor(state: AccountState = { ...emptyState(OWNER), bootstrap: bootstrap(), snapshot: snapshot() }) { this.states.set(state.owner, clone(state)); }
  async read(owner: string): Promise<AccountState | null> { await this.updates.catch(() => undefined); return this.states.has(owner) ? clone(this.states.get(owner)!) : null; }
  async update(owner: string, fn: (state: AccountState) => AccountState): Promise<AccountState> {
    const next = this.updates.catch(() => undefined).then(() => {
      const state = fn(clone(this.states.get(owner) ?? emptyState(owner)));
      this.states.set(owner, clone(state));
      return clone(state);
    });
    this.updates = next;
    return next;
  }
}

function receipt(payload: SalePayload, sequence = 1): Receipt {
  const offline = 'expected' in payload ? payload as OfflineSalePayload : null;
  const calculated = quote(snapshot(), payload.items, payload.price_tier, payload.discount_percent);
  const total = (offline?.expected.total_cents ?? calculated.total_cents) / 100;
  return { id: sequence, number: `SR-QA-${sequence}`, location_id: payload.location_id, customer_id: null, status: 'completed',
    price_tier: payload.price_tier, cash_session_id: offline?.expected.cash_session_id ?? 1, source: 'pos', simulated: false,
    channel_id: null, channel_name: null, external_reference: null, returned_amount: 0, net_total: total, cashier_name: 'QA administrator',
    discount_percent: payload.discount_percent, tax_percent: payload.tax_percent, created_at: new Date(TIME).toISOString(),
    offline: offline ? { recorded_at: offline.recorded_at, synced_at: new Date(TIME).toISOString() } : null, returned_at: null,
    items: payload.items.map(item => ({ ...item, name: 'Test shirt', sku: 'TEST-M-RED', unit_price: 100, line_total: item.quantity * 100,
      discount: 20, tax: 21.6, total, returned_quantity: 0, returnable_quantity: item.quantity, returnable_amount: total, returned_amount: 0 })),
    returns: [], payments: clone(payload.payments), subtotal: calculated.subtotal_cents / 100, discount: calculated.discount_cents / 100,
    tax: calculated.tax_cents / 100, total, paid: payload.payments[0].amount, change: payload.payments[0].amount - total };
}

class FakeClient implements MobileClient {
  readonly baseURL = API;
  captured = snapshot();
  profile = bootstrap();
  calls: SalePayload[] = [];
  committed = new Map<string, Receipt>();
  loseNextResponse = false;
  failure: ApiError | null = null;
  active = 0;
  highestActive = 0;
  async bootstrap(): Promise<Bootstrap> { return clone(this.profile); }
  async snapshot(): Promise<Snapshot> { return clone(this.captured); }
  async createSale(payload: SalePayload): Promise<Receipt> {
    this.calls.push(clone(payload));
    this.active++;
    this.highestActive = Math.max(this.highestActive, this.active);
    try {
      await Promise.resolve();
      if (this.failure) { throw this.failure; }
      let result = this.committed.get(payload.idempotency_key);
      if (!result) {
        result = receipt(payload, this.committed.size + 1);
        this.committed.set(payload.idempotency_key, clone(result));
        this.captured.products[0].stocks.find(stock => stock.location_id === payload.location_id)!.available_quantity -= payload.items[0].quantity;
      }
      if (this.loseNextResponse) { this.loseNextResponse = false; throw new ApiError('Lost response after server commit.'); }
      return clone(result);
    } finally { this.active--; }
  }
  createOfflineSale(payload: OfflineSalePayload): Promise<Receipt> { return this.createSale(payload); }
}

function fixture() {
  const store = new MemoryStore();
  let now = TIME;
  let sequence = 0;
  const engine = new OfflineEngine(store, { now: () => now, id: () => `qa-request-${++sequence}` });
  return { store, engine, client: new FakeClient(), clock: (value: number) => { now = value; } };
}

test('integer-cent quote matches discounts, tax, wholesale and half-up rounding', () => {
  assert.equal(cents(0.29), 29);
  assert.equal(percentage(1, 5000), 1);
  assert.equal(percentage(9007199254740991, 9999), 9006298534815517);
  const result = quote(snapshot(), input().items, 'retail', 10);
  assert.deepEqual({ subtotal: result.subtotal_cents, discount: result.discount_cents, tax: result.tax_cents, total: result.total_cents },
    { subtotal: 20000, discount: 2000, tax: 2160, total: 20160 });
  assert.equal(quote(snapshot(), [{ product_id: 1, quantity: 1 }], 'wholesale', 0).total_cents, 8960);
  assert.throws(() => cents(10.001), /two decimal/);
  assert.throws(() => quote(snapshot(), [{ product_id: 1, quantity: 1 }, { product_id: 1, quantity: 1 }], 'retail', 0), /selected products/);
});

test('cash outbox is durable before receipt display and exact payload survives restart', async () => {
  const { store, engine } = fixture();
  const queued = await engine.enqueueOffline(OWNER, input());
  assert.equal(queued.status, 'pending');
  assert.equal(queued.payload.idempotency_key, 'offline-qa-request-1');
  assert.equal((queued.payload as OfflineSalePayload).expected.cash_session_id, 1);
  assert.equal((queued.payload as OfflineSalePayload).expected.total_cents, 20160);
  const saved = await store.read(OWNER);
  assert.deepEqual(saved!.queue[0].payload, queued.payload);
  queued.payload.items[0].quantity = 99;
  assert.equal((await store.read(OWNER))!.queue[0].payload.items[0].quantity, 2);
  assert.equal(available(saved!.snapshot!.products[0], 1, saved!), 16);
  assert.equal(available(saved!.snapshot!.products[0], 2, saved!), 7);
  assert.equal(new OfflineEngine(store).remainingEntries(saved!).length, 1);
});

test('failed device save sends no sale request and displays no provisional confirmation', async () => {
  const { client } = fixture();
  const store: Store = { read: async () => null, update: async () => { throw new Error('Disk full'); } };
  const engine = new OfflineEngine(store, { now: () => TIME, id: () => 'disk-full-test' });
  await assert.rejects(engine.enqueueOffline(OWNER, input()), /Disk full/);
  assert.equal(client.calls.length, 0);
});

test('saved stock accounts for central reservations and other local queued sales', async () => {
  const { engine, store } = fixture();
  await engine.enqueueOffline(OWNER, input({ items: [{ product_id: 1, quantity: 17 }], cashAmount: 2000 }));
  await assert.rejects(engine.enqueueOffline(OWNER, input()), /available stock/);
  assert.equal(available(snapshot().products[0], 1, (await store.read(OWNER))!), 1);
});

test('catalog expiry, account pause, missing permission and changed permit stop new checkout', async () => {
  const { store, engine, clock } = fixture();
  clock(TIME + 86400001);
  await assert.rejects(engine.enqueueOffline(OWNER, input()), /Reconnect and refresh/);
  clock(TIME);
  await assert.rejects(engine.enqueueOffline(OWNER, input({ snapshotPermit: 'other-permit' })), /catalog changed/);
  await store.update(OWNER, state => ({ ...state, bootstrap: { ...state.bootstrap!, abilities: ['catalog:read'] } }));
  await assert.rejects(engine.enqueueOffline(OWNER, input()), /Reconnect and refresh/);
  assert.equal(remainingEntries((await store.read(OWNER))!).length, 0);
});

test('server time offset supports incorrect device clock without extending signed lease', async () => {
  const { engine, client, store, clock } = fixture();
  clock(TIME + 7 * 86400000);
  await engine.refreshSnapshot(OWNER, client);
  const queued = await engine.enqueueOffline(OWNER, input());
  assert.equal((queued.payload as OfflineSalePayload).recorded_at, new Date(TIME).toISOString());
  clock(TIME + 8 * 86400000 + 1);
  assert.equal(isSnapshotFresh((await store.read(OWNER))!.snapshot, TIME + 8 * 86400000 + 1), false);
  await assert.rejects(engine.enqueueOffline(OWNER, input()), /Reconnect and refresh/);
});

test('lost response retries original exact immutable sale and central stock changes once', async () => {
  const { engine, client, store } = fixture();
  const original = await engine.enqueueOffline(OWNER, input());
  client.loseNextResponse = true;
  await assert.rejects(engine.synchronize(OWNER, client), /Lost response/);
  assert.equal((await store.read(OWNER))!.queue[0].status, 'pending');
  assert.equal(client.committed.size, 1);
  const resumed = new OfflineEngine(store, { now: () => TIME, id: () => 'other-request' });
  const result = await resumed.synchronize(OWNER, client);
  assert.equal(result.confirmed, 1);
  assert.equal(result.state.queue[0].status, 'confirmed');
  assert.deepEqual(client.calls[0], original.payload);
  assert.deepEqual(client.calls[1], original.payload);
  assert.equal(client.committed.size, 1);
  assert.equal(available(result.state.snapshot!.products[0], 1, result.state), 16);
});

test('concurrent synchronization is serialized and never submits a confirmed sale twice', async () => {
  const { engine, client } = fixture();
  await engine.enqueueOffline(OWNER, input());
  await engine.enqueueOffline(OWNER, input({ items: [{ product_id: 1, quantity: 1 }], cashAmount: 125 }));
  const [first, second] = await Promise.all([engine.synchronize(OWNER, client), engine.synchronize(OWNER, client)]);
  assert.equal(first.confirmed, 2);
  assert.equal(second.confirmed, 0);
  assert.equal(client.calls.length, 2);
  assert.equal(client.highestActive, 1);
});

test('review conflicts remain visible and preserve their quote, cash and key', async () => {
  const { engine, client, store } = fixture();
  const original = await engine.enqueueOffline(OWNER, input());
  client.failure = new ApiError('The cash shift changed or closed.', 409);
  const result = await engine.synchronize(OWNER, client);
  assert.equal(result.conflicts, 1);
  assert.equal(result.state.queue[0].status, 'conflict');
  assert.deepEqual(result.state.queue[0].payload, original.payload);
  assert.equal(available(result.state.snapshot!.products[0], 1, result.state), 16);
  assert.match((await engine.cashShiftBlock(OWNER, 1))!, /original shift/);
  client.failure = null;
  await engine.synchronize(OWNER, client);
  assert.equal((await store.read(OWNER))!.queue[0].status, 'confirmed');
});

for (const status of [401, 403, 423]) {
  test(`access failure ${status} invalidates catalog and keeps cash queue for original-account recovery`, async () => {
    const { engine, client, store } = fixture();
    const original = await engine.enqueueOffline(OWNER, input());
    client.failure = new ApiError('Sign in or review account access.', status);
    await assert.rejects(engine.synchronize(OWNER, client), (error: unknown) => error instanceof ApiError && error.status === status);
    const saved = (await store.read(OWNER))!;
    assert.equal(saved.snapshot, null);
    assert.equal(saved.bootstrap!.access.can_write, false);
    assert.deepEqual(saved.queue[0].payload, original.payload);
    await assert.rejects(engine.enqueueOffline(OWNER, input()), /Reconnect and refresh/);
    client.failure = null;
    assert.equal((await engine.synchronize(OWNER, client)).confirmed, 1);
  });
}

for (const status of [401, 403]) {
  test(`online checkout preflight ${status} pauses cached checkout without erasing original requests`, async () => {
    const { engine, store, client } = fixture();
    const pending = await engine.enqueueOffline(OWNER, input());
    client.bootstrap = async () => { throw new ApiError('This device must sign in again.', status); };
    await assert.rejects(engine.checkoutOnline(OWNER, input(), client), (error: unknown) => error instanceof ApiError && error.status === status);
    const state = (await store.read(OWNER))!;
    assert.equal(state.snapshot, null);
    assert.equal(state.bootstrap!.access.can_write, false);
    assert.deepEqual(state.queue[0].payload, pending.payload);
    assert.equal(state.queue.length, 1);
    assert.equal(client.calls.length, 0);
    await assert.rejects(engine.enqueueOffline(OWNER, input()), /Reconnect and refresh/);
  });
}

test('logout preserves pending original-owner data and prevents an in-flight snapshot from restoring catalog', async () => {
  const { engine, client, store } = fixture();
  await store.update(OWNER, state => ({ ...state, receiptBranding: { owner: OWNER, version: 'qa-logo-v1',
    logoDataUri: 'data:image/png;base64,fictitious', address: 'Fictitious QA address', contact: 'QA contact',
    footer: 'Thank you.', fetchedAt: new Date(TIME).toISOString() } }));
  await engine.enqueueOffline(OWNER, input());
  let release!: (value: Snapshot) => void;
  let started!: () => void;
  const startedPromise = new Promise<void>(resolve => { started = resolve; });
  client.snapshot = async () => { started(); return new Promise<Snapshot>(resolve => { release = resolve; }); };
  const refresh = engine.refreshSnapshot(OWNER, client);
  await startedPromise;
  await engine.signOut(OWNER);
  release(snapshot());
  await assert.rejects(refresh, /account or another catalog refresh changed/);
  const saved = (await store.read(OWNER))!;
  assert.equal(saved.snapshot, null);
  assert.equal(saved.bootstrap, null);
  assert.equal(saved.receiptBranding, undefined);
  assert.equal(saved.queue.length, 1);
  assert.equal(saved.queue[0].status, 'pending');
});

test('foreign company, user or API origin cannot refresh or send another owner queue', async () => {
  const { engine, client, store } = fixture();
  await engine.enqueueOffline(OWNER, input());
  client.profile.tenant.id = 2;
  await assert.rejects(engine.synchronize(OWNER, client), /original company account/);
  assert.equal(client.calls.length, 0);
  assert.equal((await store.read(OWNER))!.queue.length, 1);
  const foreign = Object.assign(new FakeClient(), { baseURL: 'https://other.example.test/api/v1' });
  await assert.rejects(engine.synchronize(OWNER, foreign), /original POSONLY server/);
  assert.notEqual(ownerFor(API, 1, 1), ownerFor(API, 1, 2));
  assert.notEqual(ownerFor(API, 1, 1), ownerFor('https://other.example.test', 1, 1));
});

test('newer overlapping catalog refresh wins and stale snapshot cannot overwrite it', async () => {
  const { engine, client, store } = fixture();
  let firstRelease!: (value: Snapshot) => void;
  let started!: () => void;
  const firstStarted = new Promise<void>(resolve => { started = resolve; });
  let call = 0;
  client.snapshot = async () => {
    if (++call === 1) { started(); return new Promise<Snapshot>(resolve => { firstRelease = resolve; }); }
    return { ...snapshot(), captured_at: new Date(TIME + 1000).toISOString(), permit: 'new-signed-permit' };
  };
  const first = engine.refreshSnapshot(OWNER, client);
  await firstStarted;
  await engine.refreshSnapshot(OWNER, client);
  firstRelease(snapshot());
  await assert.rejects(first, /another catalog refresh changed/);
  assert.equal((await store.read(OWNER))!.snapshot!.permit, 'new-signed-permit');
});

test('snapshot requested before confirmation cannot acknowledge that stock deduction', async () => {
  const { engine, store, client } = fixture();
  await engine.enqueueOffline(OWNER, input());
  let release!: (value: Snapshot) => void;
  let started!: () => void;
  const began = new Promise<void>(resolve => { started = resolve; });
  client.snapshot = async () => { started(); return new Promise<Snapshot>(resolve => { release = resolve; }); };
  const refresh = engine.refreshSnapshot(OWNER, client);
  await began;
  await store.update(OWNER, state => ({ ...state, queue: state.queue.map(entry => ({ ...entry, status: 'confirmed', receipt: receipt(entry.payload) })) }));
  release(snapshot());
  const state = await refresh;
  assert.equal(state.queue[0].stockAcknowledged, false);
  assert.equal(available(state.snapshot!.products[0], 1, state), 16);
  client.snapshot = async () => { const next = snapshot(); next.products[0].stocks[0].available_quantity = 16; return next; };
  const acknowledged = await engine.refreshSnapshot(OWNER, client);
  assert.equal(acknowledged.queue[0].stockAcknowledged, true);
  assert.equal(available(acknowledged.snapshot!.products[0], 1, acknowledged), 16);
});

test('unknown online checkout is durable before POST and blocks a new offline sale across restart and logout', async () => {
  const { engine, client, store } = fixture();
  let persistedBeforePost = false;
  const original = client.createSale.bind(client);
  client.createSale = async payload => {
    persistedBeforePost = (await store.read(OWNER))!.queue.some(entry => entry.payload.idempotency_key === payload.idempotency_key);
    return original(payload);
  };
  client.loseNextResponse = true;
  await assert.rejects(engine.checkoutOnline(OWNER, input(), client), /Lost response/);
  assert.equal(persistedBeforePost, true);
  const savedPayload = clone((await store.read(OWNER))!.queue[0].payload);
  await assert.rejects(new OfflineEngine(store, { now: () => TIME }).enqueueOffline(OWNER, input()), /online checkout still needs confirmation/);
  await engine.signOut(OWNER);
  assert.deepEqual((await store.read(OWNER))!.queue[0].payload, savedPayload);
  const result = await new OfflineEngine(store, { now: () => TIME }).synchronize(OWNER, client);
  assert.equal(result.confirmed, 1);
  assert.deepEqual(client.calls[1], savedPayload);
  assert.equal(client.committed.size, 1);
});

test('unverified receipt leaves original cash request unconfirmed', async () => {
  const { engine, client, store } = fixture();
  const original = await engine.enqueueOffline(OWNER, input());
  client.createOfflineSale = async payload => ({ ...receipt(payload), total: 1 });
  await assert.rejects(engine.synchronize(OWNER, client), /original quote/);
  assert.deepEqual((await store.read(OWNER))!.queue[0].payload, original.payload);
  assert.equal((await store.read(OWNER))!.queue[0].status, 'pending');
});

test('new queued timestamps use server seconds and old millisecond requests recover without payload changes', async () => {
  const { engine, client, store, clock } = fixture();
  clock(TIME + 456);
  const queued = await engine.enqueueOffline(OWNER, input());
  assert.equal((queued.payload as OfflineSalePayload).recorded_at, new Date(TIME).toISOString());
  await store.update(OWNER, state => ({ ...state, queue: state.queue.map(entry => ({ ...entry,
    payload: { ...entry.payload, recorded_at: new Date(TIME + 456).toISOString() } as OfflineSalePayload })) }));
  const original = clone((await store.read(OWNER))!.queue[0].payload);
  client.createOfflineSale = async payload => {
    client.calls.push(clone(payload));
    return { ...receipt(payload), offline: { recorded_at: new Date(Math.floor(Date.parse(payload.recorded_at) / 1000) * 1000).toISOString(),
      synced_at: new Date(TIME).toISOString() } };
  };
  assert.equal((await engine.synchronize(OWNER, client)).confirmed, 1);
  assert.deepEqual(client.calls[0], original);
  assert.deepEqual((await store.read(OWNER))!.queue[0].payload, original);
});

test('receipt recorded time from a different second cannot confirm original queued sale', async () => {
  const { engine, client, store } = fixture();
  await engine.enqueueOffline(OWNER, input());
  client.createOfflineSale = async payload => ({ ...receipt(payload), offline: {
    recorded_at: new Date(Date.parse(payload.recorded_at) + 1000).toISOString(), synced_at: new Date(TIME).toISOString(),
  } });
  await assert.rejects(engine.synchronize(OWNER, client), /original quote/);
  assert.equal((await store.read(OWNER))!.queue[0].status, 'pending');
});

test('pending cash or stock actions guard a new sale and survive logout', async () => {
  const { engine, store } = fixture();
  await store.update(OWNER, state => ({ ...state, pendingAction: { kind: 'stock-adjust', path: '/stock/adjust',
    payload: { idempotency_key: 'stock-adjust-immutable' }, createdAt: new Date(TIME).toISOString() } }));
  await assert.rejects(engine.enqueueOffline(OWNER, input()), /action still needs confirmation/);
  const signedOut = await engine.signOut(OWNER);
  assert.equal(signedOut.pendingAction!.payload.idempotency_key, 'stock-adjust-immutable');
});

test('API only sends bearer to its fixed origin and public login has no bearer or cookie credentials', async () => {
  const calls: { url: string; options: RequestInit }[] = [];
  const transport = async (url: string | URL | Request, options: RequestInit = {}) => {
    calls.push({ url: String(url), options });
    return new Response(JSON.stringify(String(url).endsWith('/auth/token')
      ? { token_type: 'Bearer', token: '1|fictitious-token', expires_at: new Date(TIME + 86400000).toISOString(), abilities: ['catalog:read'] }
      : bootstrap()), { status: String(url).endsWith('/auth/token') ? 201 : 200, headers: { 'Content-Type': 'application/json' } });
  };
  const client = new ApiClient(ORIGIN, { token: 'private-token', fetch: transport as typeof fetch });
  await client.authToken({ email: 'qa@example.test', password: 'fictitious', device_name: 'QA phone' });
  await client.bootstrap();
  assert.equal((calls[0].options.headers as Record<string, string>).Authorization, undefined);
  assert.equal((calls[1].options.headers as Record<string, string>).Authorization, 'Bearer private-token');
  for (const call of calls) {
    assert.equal((call.options.headers as Record<string, string>)['Cache-Control'], 'no-store');
    assert.equal((call.options.headers as Record<string, string>).Pragma, 'no-cache');
  }
  assert.equal(calls[1].options.redirect, 'error');
  assert.equal(calls[1].options.credentials, 'omit');
  await assert.rejects(client.request('https://outside.example.test/steal'), /outside the configured API/);
  await assert.rejects(client.request('/../../developer'), /outside the configured API/);
  assert.equal(calls.length, 2);
  assert.equal(normalizeApiUrl('https://posonly.example.test/api/v1/'), API);
  assert.throws(() => new ApiClient('http://posonly.com', { allowInsecureDevelopment: true }), /HTTPS/);
  assert.throws(() => new ApiClient('https://user:password@posonly.com'), /HTTPS/);
  assert.equal(new ApiClient('http://127.0.0.1:8092', { allowInsecureDevelopment: true }).baseURL, 'http://127.0.0.1:8092/api/v1');
});

test('API bounds timeout even when transport ignores abort and marks failed POST uncertain', async () => {
  const client = new ApiClient(ORIGIN, { token: 'fictitious', timeoutMs: 5,
    fetch: (() => new Promise<Response>(() => undefined)) as typeof fetch });
  await assert.rejects(client.createSale({ location_id: 1, price_tier: 'retail', items: [], discount_percent: 0,
    tax_percent: 0, payments: [], idempotency_key: 'fixed-key-keep-original' }),
  (error: unknown) => error instanceof ApiError && error.uncertain && error.status === null);
});

test('API invokes the browser and native fetch transport with its global receiver', async () => {
  let called = false;
  const transport = function (this: typeof globalThis): Promise<Response> {
    assert.equal(this, globalThis);
    called = true;
    return Promise.resolve(new Response(JSON.stringify(bootstrap()), { status: 200 }));
  };
  const client = new ApiClient(ORIGIN, { token: 'fictitious-token', fetch: transport as typeof fetch });
  assert.equal((await client.bootstrap()).tenant.id, 1);
  assert.equal(called, true);
});

test('API handles field validation, auth expiry, malformed successful JSON and redirects safely', async () => {
  let response = new Response(JSON.stringify({ message: 'Invalid input', errors: { email: ['Incorrect account credentials.'] } }), { status: 422 });
  const client = new ApiClient(ORIGIN, { token: 'fictitious', fetch: (async () => response) as typeof fetch });
  await assert.rejects(client.bootstrap(), (error: unknown) => error instanceof ApiError && error.status === 422 && !error.uncertain && /Incorrect account/.test(error.message));
  response = new Response(JSON.stringify({ message: 'Unauthenticated.' }), { status: 401 });
  await assert.rejects(client.bootstrap(), (error: unknown) => error instanceof ApiError && error.status === 401);
  response = new Response('<html>unexpected page</html>', { status: 200 });
  await assert.rejects(client.bootstrap(), (error: unknown) => error instanceof ApiError && error.uncertain);
  response = new Response('{}', { status: 200 });
  Object.defineProperty(response, 'redirected', { value: true });
  await assert.rejects(client.bootstrap(), /redirected unexpectedly/);
});

test('API 5xx hides server diagnostics while retaining an uncertain status and original retry guidance', async () => {
  const client = new ApiClient(ORIGIN, { token: 'fictitious', fetch: (async () => new Response(JSON.stringify({
    message: 'Internal private provider diagnostic', errors: { debug: ['Private server credential diagnostic'] },
    trace: 'Private exception stack',
  }), { status: 503 })) as typeof fetch });
  await assert.rejects(client.bootstrap(), (error: unknown) => {
    assert.ok(error instanceof ApiError);
    assert.equal(error.status, 503);
    assert.equal(error.uncertain, true);
    assert.equal(error.message, 'Connection interrupted. Keep the original request and retry when connected.');
    assert.deepEqual(error.errors, {});
    return true;
  });
});
