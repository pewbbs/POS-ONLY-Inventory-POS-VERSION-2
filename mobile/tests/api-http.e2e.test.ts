import assert from 'node:assert/strict';
import { createHash, randomUUID } from 'node:crypto';
import { chmodSync, mkdtempSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, join } from 'node:path';
import { DatabaseSync } from 'node:sqlite';
import test from 'node:test';
import { ApiClient, ApiError } from '../src/core/api';
import { emptyState, OfflineEngine, ownerFor } from '../src/core/offline';
import type { AccountState, OfflineSalePayload, Product, Store } from '../src/core/types';

const TEST_SERVER = 'http://127.0.0.1:8092';
const enabled = process.env.POSONLY_TEST_API_URL === TEST_SERVER;

/** A durable SQLite implementation of the same owner-scoped Store contract used by the app. */
class TestSqliteStore implements Store {
  private readonly database: DatabaseSync;
  private pending: Promise<unknown> = Promise.resolve();

  constructor(path: string) {
    this.database = new DatabaseSync(path);
    this.database.exec('PRAGMA journal_mode = WAL; CREATE TABLE IF NOT EXISTS accounts (owner TEXT PRIMARY KEY NOT NULL, body TEXT NOT NULL);');
    chmodSync(path, 0o600);
  }

  async read(owner: string): Promise<AccountState | null> {
    await this.pending.catch(() => undefined);
    const row = this.database.prepare('SELECT body FROM accounts WHERE owner = ?').get(owner);
    return row ? JSON.parse(String(row.body)) as AccountState : null;
  }

  update(owner: string, operation: (state: AccountState) => AccountState): Promise<AccountState> {
    const task = this.pending.catch(() => undefined).then(() => {
      this.database.exec('BEGIN IMMEDIATE');
      try {
        const row = this.database.prepare('SELECT body FROM accounts WHERE owner = ?').get(owner);
        const current = row ? JSON.parse(String(row.body)) as AccountState : emptyState(owner);
        const result = operation(current);
        assert.equal(result.owner, owner, 'A transaction cannot move data to another account.');
        const body = JSON.stringify(result);
        this.database.prepare('INSERT INTO accounts(owner, body) VALUES(?, ?) ON CONFLICT(owner) DO UPDATE SET body = excluded.body').run(owner, body);
        this.database.exec('COMMIT');
        return JSON.parse(body) as AccountState;
      } catch (error) {
        this.database.exec('ROLLBACK');
        throw error;
      }
    });
    this.pending = task;
    return task;
  }

  close(): void { this.database.close(); }
}

interface Fixture {
  tenant_id: number;
  cashier_id: number;
  shop_id: number;
  branch_id: number;
  product_ids: number[];
  cashier_session_id: number;
  foreign_tenant_id: number;
  foreign_user_id: number;
  foreign_product_id: number;
}

interface Baseline { [table: string]: { count: number; sha256: string } }

function canonicalRows(rows: Record<string, unknown>[]): string {
  return JSON.stringify(rows.map(row => Object.fromEntries(Object.keys(row).sort().map(key => [key, row[key]]))));
}

function verifyProtectedRecords(database: DatabaseSync, root: string): void {
  for (const file of ['protected-baseline.json', 'foreign-company-baseline.json']) {
    const baseline = JSON.parse(readFileSync(join(root, file), 'utf8')) as Baseline;
    for (const [table, expected] of Object.entries(baseline)) {
      assert.match(table, /^[a-z_]+$/);
      const condition = file === 'foreign-company-baseline.json' ? ' WHERE tenant_id = 2' : '';
      const rows = database.prepare(`SELECT * FROM ${table}${condition} ORDER BY id`).all();
      const digest = createHash('sha256').update(canonicalRows(rows)).digest('hex');
      assert.equal(rows.length, expected.count, `${table}: protected record count changed.`);
      assert.equal(digest, expected.sha256, `${table}: protected record content changed.`);
    }
  }
}

function stock(product: Product, locationId: number): number {
  const row = product.stocks.find(value => value.location_id === locationId);
  assert.ok(row, 'The product needs stock at the selected test location.');
  return row.quantity;
}

function status(expected: number): (error: unknown) => boolean {
  return error => error instanceof ApiError && error.status === expected;
}

test('real mobile API preserves queued cash sales across restart, reconnection, and uncertain responses', {
  skip: !enabled && 'Set POSONLY_TEST_API_URL=http://127.0.0.1:8092 with the private isolated backend running.',
  timeout: 45000,
}, async context => {
  const root = realpathSync(readFileSync('/tmp/posonly-mobile-isolated-copy-path', 'utf8').trim());
  assert.ok(basename(root).startsWith('posonly-mobile-isolated-'), 'This test requires the disposable mobile fixture.');
  const configuration = readFileSync(join(root, '.env'), 'utf8');
  assert.ok(configuration.includes(`APP_URL=${TEST_SERVER}\n`), 'The disposable backend must use the fixed local server.');
  const configuredDatabase = configuration.match(/^DB_DATABASE=(.+)$/m)?.[1].replace(/^"|"$/g, '');
  assert.ok(configuredDatabase && realpathSync(configuredDatabase) === join(root, 'database/mobile-isolated.sqlite'), 'The database must remain inside the disposable fixture.');
  assert.ok(configuration.includes('PAYMONGO_ENABLED=false\n') && configuration.includes('PAYMONGO_SECRET_KEY=\n')
    && configuration.includes('PAYMONGO_WEBHOOK_SECRET=\n') && configuration.includes('MAIL_MAILER=array\n'), 'External integrations must stay disabled.');
  const fixture = JSON.parse(readFileSync(join(root, 'fixture-info.json'), 'utf8')) as Fixture;
  const serverDatabase = new DatabaseSync(join(root, 'database/mobile-isolated.sqlite'), { readOnly: true });
  const deviceDirectory = mkdtempSync(join(tmpdir(), 'posonly-mobile-device-e2e-'));
  chmodSync(deviceDirectory, 0o700);
  const deviceDatabase = join(deviceDirectory, 'device.sqlite');
  let store = new TestSqliteStore(deviceDatabase);
  let online = true;
  const requests: { path: string; method: string }[] = [];
  const credentials = { password: 'password', device_name: `Isolated mobile HTTP QA ${randomUUID()}` };
  const clients: ApiClient[] = [];
  const transport: typeof fetch = async (input, init) => {
    const url = new URL(typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url);
    assert.equal(url.origin, TEST_SERVER, 'The test must never contact production or another server.');
    assert.ok(url.pathname.startsWith('/api/v1/'), 'The test must stay in the mobile API.');
    assert.ok(!url.pathname.includes('billing') && !url.pathname.includes('paymongo'), 'No payment provider request is permitted.');
    requests.push({ path: url.pathname, method: init?.method ?? 'GET' });
    if (!online) { throw new Error('The isolated device is disconnected.'); }
    return fetch(input, init);
  };
  const makeClient = (token?: string, timeoutMs = 15000) => new ApiClient(TEST_SERVER, {
    token, allowInsecureDevelopment: true, timeoutMs, fetch: transport,
  });
  const login = async (email: string): Promise<{ client: ApiClient; token: string }> => {
    const response = await makeClient().authToken({ ...credentials, email });
    const client = makeClient(response.token);
    clients.push(client);
    return { client, token: response.token };
  };
  const confirmedCount = (key: string): number => Number(serverDatabase.prepare('SELECT COUNT(*) AS count FROM sales WHERE idempotency_key = ?').get(key)?.count);
  const step = async (name: string, operation: () => void | Promise<void>): Promise<void> => {
    let failure: unknown;
    await context.test(name, async () => {
      try { await operation(); } catch (error) { failure = error; throw error; }
    });
    if (failure) { throw failure; }
  };

  try {
    verifyProtectedRecords(serverDatabase, root);
    const cashierLogin = await login('mobile.cashier@example.test');
    const setupDevices = await cashierLogin.client.devices();
    for (const device of setupDevices.data.filter(value => value.name === 'Temporary fictitious credential check')) {
      await cashierLogin.client.revokeDevice(device.id);
    }
    const adminLogin = await login('mobile.admin@example.test');
    const foreignLogin = await login('other.mobile.admin@example.test');
    let cashier = cashierLogin.client;
    const owner = ownerFor(cashier.baseURL, fixture.tenant_id, fixture.cashier_id);
    let engine = new OfflineEngine(store);
    const productId = fixture.product_ids[1];
    let originalStock = 0;
    let originalBranchStock = 0;
    let originalCash = 0;

    await step('real sign-in exposes the original company, barcode variant, and cashier permissions', async () => {
      const account = await cashier.bootstrap();
      assert.equal(account.tenant.id, fixture.tenant_id);
      assert.equal(account.user.id, fixture.cashier_id);
      assert.equal(account.user.role, 'cashier');
      assert.equal(account.settings.tax_percent, 12);
      assert.equal(account.access.can_write, true);
      assert.ok(account.abilities.includes('sales:write'));
      assert.ok(!account.abilities.includes('inventory:write'));
      const catalog = await cashier.getProducts({ barcode: '890000002' });
      assert.equal(catalog.data.length, 1);
      assert.equal(catalog.data[0].id, productId);
      assert.equal(catalog.data[0].price, 100);
      assert.equal(catalog.data[0].wholesale_price, 80);
      assert.equal(catalog.data[0].cost, 0);
      assert.deepEqual(catalog.data[0].variant_options, { size: 'L', color: 'Black' });
      originalStock = stock(catalog.data[0], fixture.shop_id);
      originalBranchStock = stock(catalog.data[0], fixture.branch_id);
      assert.ok(originalStock >= 6, 'Use the fresh fake product stock for the lifecycle test.');
      const session = (await cashier.getCashSessions(fixture.shop_id)).current;
      assert.equal(session?.id, fixture.cashier_session_id);
      originalCash = session!.cash_sales;
      await assert.rejects(cashier.request('/stock/adjust', 'POST', {
        product_id: productId, location_id: fixture.shop_id, quantity: 1,
        reason: 'Forbidden cashier QA', idempotency_key: randomUUID(),
      }), status(403));
      await assert.rejects(cashier.getProduct(fixture.foreign_product_id), status(404));
      assert.deepEqual((await foreignLogin.client.getProducts()).data.map(product => product.id), [fixture.foreign_product_id]);
    });

    await step('a disconnected cash sale is durable before any submission and survives a device restart', async () => {
      const saved = await engine.refreshSnapshot(owner, cashier);
      assert.equal(saved.snapshot?.cash_sessions.find(session => session.location_id === fixture.shop_id)?.id, fixture.cashier_session_id);
      online = false;
      const countBefore = requests.length;
      const entry = await engine.enqueueOffline(owner, {
        locationId: fixture.shop_id, items: [{ product_id: productId, quantity: 1 }],
        tier: 'retail', discountPercent: 0, cashAmount: 120, snapshotPermit: saved.snapshot!.permit,
      });
      assert.equal(requests.length, countBefore, 'Recording a disconnected sale cannot send an HTTP request.');
      assert.equal(entry.quote.total_cents, 11200);
      assert.equal((entry.payload as OfflineSalePayload).expected.cash_session_id, fixture.cashier_session_id);
      assert.equal(confirmedCount(entry.payload.idempotency_key), 0);
      assert.match((await engine.cashShiftBlock(owner, fixture.cashier_session_id))!, /Sync the queued cash sales/);
      await assert.rejects(engine.synchronize(owner, cashier), error => error instanceof ApiError && error.uncertain);
      store.close();
      store = new TestSqliteStore(deviceDatabase);
      engine = new OfflineEngine(store);
      const restored = await store.read(owner);
      assert.equal(restored?.queue[0].payload.idempotency_key, entry.payload.idempotency_key);
      assert.deepEqual(restored?.queue[0].payload, entry.payload);
      assert.equal(restored?.queue[0].status, 'pending');
      assert.equal(engine.available(restored!.snapshot!.products.find(product => product.id === productId)!, fixture.shop_id, restored!), originalStock - 1);
      online = true;
    });

    await step('reconnection confirms one receipt, deducts stock once, and credits the original cash shift', async () => {
      const result = await engine.synchronize(owner, cashier);
      assert.equal(result.confirmed, 1);
      assert.equal(result.conflicts, 0);
      const entry = result.state.queue[0];
      assert.equal(entry.status, 'confirmed');
      assert.equal(entry.stockAcknowledged, true);
      assert.equal(entry.receipt?.cash_session_id, fixture.cashier_session_id);
      assert.equal(entry.receipt?.total, 112);
      assert.equal(entry.receipt?.change, 8);
      assert.equal(confirmedCount(entry.payload.idempotency_key), 1);
      const product = await cashier.getProduct(productId);
      assert.equal(stock(product, fixture.shop_id), originalStock - 1);
      assert.equal(stock(product, fixture.branch_id), originalBranchStock);
      assert.equal((await cashier.getCashSessions(fixture.shop_id)).current?.cash_sales, originalCash + 112);
      assert.equal((await cashier.getSale(entry.receipt!.id)).number, entry.receipt?.number);
      await assert.rejects(foreignLogin.client.getSale(entry.receipt!.id), status(404));
      assert.equal(await engine.cashShiftBlock(owner, fixture.cashier_session_id), null);
    });

    await step('a committed sale with a lost response retries its original payload without a second deduction', async () => {
      const saved = await store.read(owner);
      const entry = await engine.enqueueOffline(owner, {
        locationId: fixture.shop_id, items: [{ product_id: productId, quantity: 1 }],
        tier: 'wholesale', discountPercent: 0, cashAmount: 100, snapshotPermit: saved!.snapshot!.permit,
      });
      assert.equal(entry.quote.total_cents, 8960);
      writeFileSync(join(root, '.qa-lose-next-offline-response'), '1200', { mode: 0o600 });
      await assert.rejects(engine.synchronize(owner, makeClient(cashierLogin.token, 200)), error => error instanceof ApiError && error.uncertain);
      assert.equal(confirmedCount(entry.payload.idempotency_key), 1, 'The server committed before the response timed out.');
      const pending = (await store.read(owner))!.queue.find(value => value.id === entry.id)!;
      assert.equal(pending.status, 'pending');
      assert.deepEqual(pending.payload, entry.payload);
      const recovered = await engine.synchronize(owner, cashier);
      assert.equal(recovered.confirmed, 1);
      const confirmed = recovered.state.queue.find(value => value.id === entry.id)!;
      assert.equal(confirmed.status, 'confirmed');
      assert.equal(confirmed.receipt?.total, 89.6);
      assert.equal(confirmedCount(entry.payload.idempotency_key), 1);
      assert.equal(stock(await cashier.getProduct(productId), fixture.shop_id), originalStock - 2);
      assert.equal((await cashier.getCashSessions(fixture.shop_id)).current?.cash_sales, originalCash + 201.6);
    });

    await step('sign-out and revoked credentials keep the queue for its original account and allow recovery after login', async () => {
      const state = (await store.read(owner))!;
      const entry = await engine.enqueueOffline(owner, {
        locationId: fixture.shop_id, items: [{ product_id: productId, quantity: 1 }],
        tier: 'retail', discountPercent: 0, cashAmount: 112, snapshotPermit: state.snapshot!.permit,
      });
      const signedOut = await engine.signOut(owner);
      assert.equal(signedOut.snapshot, null);
      assert.equal(signedOut.bootstrap, null);
      assert.deepEqual(signedOut.queue.map(value => value.id), [entry.id]);
      await cashier.revoke();
      await assert.rejects(engine.synchronize(owner, cashier), status(401));
      assert.equal((await store.read(owner))?.queue[0].payload.idempotency_key, entry.payload.idempotency_key);
      await assert.rejects(engine.synchronize(owner, foreignLogin.client), status(403));
      const foreignOwner = ownerFor(foreignLogin.client.baseURL, fixture.foreign_tenant_id, fixture.foreign_user_id);
      assert.equal(await store.read(foreignOwner), null);
      cashier = (await login('mobile.cashier@example.test')).client;
      const recovered = await engine.synchronize(owner, cashier);
      assert.equal(recovered.confirmed, 1);
      assert.equal(recovered.state.queue[0].receipt?.cash_session_id, fixture.cashier_session_id);
      assert.equal(confirmedCount(entry.payload.idempotency_key), 1);
      assert.equal(stock(await cashier.getProduct(productId), fixture.shop_id), originalStock - 3);
    });

    await step('stock conflicts retain the immutable request and recover when the original stock is replenished', async () => {
      const state = (await store.read(owner))!;
      const entry = await engine.enqueueOffline(owner, {
        locationId: fixture.shop_id, items: [{ product_id: productId, quantity: 1 }],
        tier: 'retail', discountPercent: 0, cashAmount: 112, snapshotPermit: state.snapshot!.permit,
      });
      const quantity = stock(await adminLogin.client.getProduct(productId), fixture.shop_id);
      await adminLogin.client.request('/stock/adjust', 'POST', {
        product_id: productId, location_id: fixture.shop_id, quantity: -quantity,
        reason: 'Isolated mobile conflict test', idempotency_key: randomUUID(),
      });
      const conflict = await engine.synchronize(owner, cashier);
      assert.equal(conflict.confirmed, 0);
      assert.equal(conflict.conflicts, 1);
      const saved = conflict.state.queue.find(value => value.id === entry.id)!;
      assert.equal(saved.status, 'conflict');
      assert.deepEqual(saved.payload, entry.payload);
      assert.equal(confirmedCount(entry.payload.idempotency_key), 0);
      await adminLogin.client.request('/stock/adjust', 'POST', {
        product_id: productId, location_id: fixture.shop_id, quantity,
        reason: 'Restore isolated mobile test stock', idempotency_key: randomUUID(),
      });
      const recovered = await engine.synchronize(owner, cashier);
      assert.equal(recovered.confirmed, 1);
      assert.equal(recovered.conflicts, 0);
      assert.equal(confirmedCount(entry.payload.idempotency_key), 1);
      const product = await cashier.getProduct(productId);
      assert.equal(stock(product, fixture.shop_id), originalStock - 4);
      assert.equal(stock(product, fixture.branch_id), originalBranchStock);
      assert.equal((await cashier.getCashSessions(fixture.shop_id)).current?.cash_sales, originalCash + 425.6);
      assert.equal(await engine.cashShiftBlock(owner, fixture.cashier_session_id), null);
    });

    await step('company and billing records and all foreign-company data remain unchanged', () => {
      verifyProtectedRecords(serverDatabase, root);
      assert.ok(requests.every(request => request.path.startsWith('/api/v1/')));
      assert.ok(!requests.some(request => /billing|paymongo/.test(request.path)));
    });
  } finally {
    online = true;
    for (const client of clients) {
      try { await client.revoke(); } catch (error) {
        if (!(error instanceof ApiError && error.status === 401)) { throw error; }
      }
    }
    store.close();
    serverDatabase.close();
    rmSync(deviceDirectory, { recursive: true, force: true });
  }
});
