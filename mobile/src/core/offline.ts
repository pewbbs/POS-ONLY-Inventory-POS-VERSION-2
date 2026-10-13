import { ApiError, normalizeApiUrl } from './api';
import type {
  AccountState, Bootstrap, EnqueueInput, MobileClient, OfflineSalePayload, OnlineInput,
  Product, QueueEntry, Quote, Receipt, SaleItem, SalePayload, Snapshot, Store, SyncResult,
} from './types';

const MAX_PENDING = 500;
const ROLES = ['admin', 'manager', 'cashier'];
const copy = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;
const validId = (value: unknown): value is number => Number.isSafeInteger(value) && Number(value) > 0;

export function ownerFor(apiUrl: string, tenantId: number, userId: number): string {
  if (!validId(tenantId) || !validId(userId)) { throw new Error('The company account could not be verified.'); }
  const url = new URL(apiUrl);
  const normalized = normalizeApiUrl(apiUrl, url.protocol === 'http:');
  return `${normalized}|${tenantId}:${userId}`;
}

export function emptyState(owner: string): AccountState {
  return { owner, bootstrap: null, snapshot: null, queue: [], revision: 0, refreshGeneration: 0 };
}

export function remainingEntries(state: AccountState): QueueEntry[] {
  return state.queue.filter(entry => entry.status !== 'confirmed');
}

export function cents(value: number): number {
  if (!Number.isFinite(value) || value < 0 || value > 1000000000) { throw new Error('Use a valid money amount.'); }
  const amount = Math.round((value + Number.EPSILON) * 100);
  if (!Number.isSafeInteger(amount) || Math.abs(value * 100 - amount) > 0.00001) {
    throw new Error('Money amounts must have at most two decimal places.');
  }
  return amount;
}

export function basisPoints(value: number): number {
  const points = Math.round(value * 100);
  if (!Number.isFinite(value) || points < 0 || points > 10000 || Math.abs(value * 100 - points) > 0.00001) {
    throw new Error('Use a percentage from 0 to 100 with at most two decimal places.');
  }
  return points;
}

/** Match Laravel's half-up integer arithmetic without unsafe amount × basis-point products. */
export function percentage(amount: number, points: number): number {
  if (!Number.isSafeInteger(amount) || amount < 0 || !Number.isInteger(points) || points < 0 || points > 10000) {
    throw new Error('This sale exceeds the supported amount limit.');
  }
  const result = Math.floor(amount / 10000) * points + Math.floor(((amount % 10000) * points + 5000) / 10000);
  if (!Number.isSafeInteger(result)) { throw new Error('This sale exceeds the supported amount limit.'); }
  return result;
}

export function serverNow(snapshot: Snapshot, now = Date.now()): number {
  return now + (snapshot.clockOffsetMs ?? 0);
}

export function isSnapshotFresh(snapshot: Snapshot | null, now = Date.now()): snapshot is Snapshot {
  if (!snapshot) { return false; }
  const current = serverNow(snapshot, now);
  return Number.isFinite(current) && current >= Date.parse(snapshot.captured_at) && current <= Date.parse(snapshot.expires_at);
}

export function available(product: Product, locationId: number, state: AccountState): number {
  const stock = product.stocks.find(row => row.location_id === locationId);
  const saved = Number(stock?.available_quantity ?? 0);
  const held = state.queue.filter(entry => entry.payload.location_id === locationId
    && (entry.status !== 'confirmed' || !entry.stockAcknowledged))
    .reduce((sum, entry) => sum + (entry.payload.items.find(item => item.product_id === product.id)?.quantity ?? 0), 0);
  return Math.max(0, saved - held);
}

export function quote(snapshot: Snapshot, items: SaleItem[], tier: 'retail' | 'wholesale', discountPercent: number): Quote {
  if (!Array.isArray(items) || !items.length || items.length > 100 || !['retail', 'wholesale'].includes(tier)
    || new Set(items.map(item => item.product_id)).size !== items.length) { throw new Error('Check the selected products and price tier.'); }
  const discounted = basisPoints(discountPercent);
  const taxed = basisPoints(snapshot.settings.tax_percent);
  const priced = items.map(item => {
    const product = snapshot.products.find(row => row.id === item.product_id);
    const price = tier === 'wholesale' ? product?.wholesale_price : product?.price;
    if (!product || !validId(item.product_id) || !Number.isInteger(item.quantity) || item.quantity < 1 || item.quantity > 100000
      || price === null || price === undefined) { throw new Error('Check the product quantity or set its wholesale price.'); }
    return { product_id: product.id, unit_price_cents: cents(price), quantity: item.quantity, name: product.name, sku: product.sku };
  });
  const subtotal = priced.reduce((sum, item) => sum + item.unit_price_cents * item.quantity, 0);
  if (!Number.isSafeInteger(subtotal)) { throw new Error('This sale exceeds the supported amount limit.'); }
  const discount = percentage(subtotal, discounted);
  const tax = percentage(subtotal - discount, taxed);
  const total = subtotal - discount + tax;
  if (!Number.isSafeInteger(total) || total > 100000000000) { throw new Error('This sale exceeds the cash tender limit.'); }
  return { items: priced, subtotal_cents: subtotal, discount_cents: discount, tax_cents: tax, total_cents: total };
}

function verifyBootstrap(value: Bootstrap, owner: string, baseURL: string): void {
  if (!value || value.api_version !== 1 || !value.user?.is_active || !ROLES.includes(value.user.role)
    || !Array.isArray(value.abilities) || !Array.isArray(value.locations) || !value.access
    || ownerFor(baseURL, value.tenant?.id, value.user?.id) !== owner) {
    throw new ApiError('Sign in to the original company account before using its saved requests.', 403);
  }
}

function verifySnapshot(value: Snapshot, owner: string, baseURL: string): void {
  if (!value || value.version !== 1 || !ROLES.includes(value.user?.role) || !value.tenant?.name
    || typeof value.permit !== 'string' || !value.permit || value.permit.length > 2000
    || !Array.isArray(value.products) || !Array.isArray(value.locations) || !Array.isArray(value.cash_sessions)
    || !value.settings || typeof value.can_write !== 'boolean'
    || !Number.isFinite(Date.parse(value.captured_at)) || !Number.isFinite(Date.parse(value.expires_at))
    || Date.parse(value.expires_at) <= Date.parse(value.captured_at)
    || ownerFor(baseURL, value.tenant.id, value.user.id) !== owner) {
    throw new ApiError('The saved catalog does not match the original company account.', 403);
  }
  basisPoints(value.settings.tax_percent);
  if (new Set(value.products.map(product => product.id)).size !== value.products.length
    || new Set(value.locations.map(location => location.id)).size !== value.locations.length) {
    throw new ApiError('The saved catalog contains duplicate records. Reconnect and refresh it.');
  }
  for (const product of value.products) {
    if (!validId(product.id) || typeof product.name !== 'string' || typeof product.sku !== 'string' || !Array.isArray(product.stocks)) {
      throw new ApiError('The saved product catalog could not be verified.');
    }
    cents(product.price);
    if (product.wholesale_price !== null) { cents(product.wholesale_price); }
    for (const stock of product.stocks) {
      if (!value.locations.some(location => location.id === stock.location_id)
        || !Number.isSafeInteger(stock.available_quantity) || stock.available_quantity < 0) {
        throw new ApiError('The saved location stock could not be verified.');
      }
    }
  }
}

function verifyReceipt(receipt: Receipt, entry: QueueEntry): void {
  const request = entry.payload;
  if (!receipt || !validId(receipt.id) || typeof receipt.number !== 'string' || !receipt.number
    || receipt.location_id !== request.location_id || receipt.price_tier !== request.price_tier
    || !Array.isArray(receipt.items) || receipt.items.length !== request.items.length
    || !Array.isArray(receipt.payments) || receipt.payments.length !== request.payments.length
    || receipt.items.some(item => !request.items.some(original => original.product_id === item.product_id && original.quantity === item.quantity))
    || receipt.payments.some((payment, index) => payment.method !== request.payments[index].method || cents(payment.amount) !== cents(request.payments[index].amount))
    || !Number.isFinite(Date.parse(receipt.created_at))) {
    throw new ApiError('The receipt response could not be verified. Retry the original protected request.');
  }
  cents(receipt.total);
  if (entry.kind === 'offline') {
    const payload = request as OfflineSalePayload;
    if (cents(receipt.total) !== payload.expected.total_cents || receipt.cash_session_id !== payload.expected.cash_session_id
      || !receipt.offline || Math.floor(Date.parse(receipt.offline.recorded_at) / 1000) !== Math.floor(Date.parse(payload.recorded_at) / 1000)) {
      throw new ApiError('The offline receipt does not match its original quote. Keep the sale and retry the same request.');
    }
  }
}

export interface OfflineEngineOptions { now?: () => number; id?: () => string }

export class OfflineEngine {
  private readonly now: () => number;
  private readonly id: () => string;
  private readonly operations = new Map<string, Promise<unknown>>();

  constructor(private readonly store: Store, options: OfflineEngineOptions = {}) {
    this.now = options.now ?? (() => Date.now());
    this.id = options.id ?? (() => {
      if (!globalThis.crypto?.randomUUID) { throw new Error('Secure request identifiers are unavailable.'); }
      return globalThis.crypto.randomUUID();
    });
  }

  private async serial<T>(owner: string, operation: () => Promise<T>): Promise<T> {
    const previous = this.operations.get(owner) ?? Promise.resolve();
    const pending = previous.catch(() => undefined).then(operation);
    this.operations.set(owner, pending);
    try { return await pending; } finally {
      if (this.operations.get(owner) === pending) { this.operations.delete(owner); }
    }
  }

  private clientFor(owner: string, client: MobileClient): void {
    if (!owner.startsWith(`${client.baseURL}|`)) { throw new ApiError('Use this request’s original POSONLY server.', 403); }
  }

  private async state(owner: string): Promise<AccountState> {
    const state = await this.store.read(owner);
    if (!state || state.owner !== owner) { throw new Error('Sign in and save this company’s catalog first.'); }
    return copy(state);
  }

  private async invalidate(owner: string): Promise<void> {
    await this.store.update(owner, state => ({ ...state, snapshot: null, bootstrap: state.bootstrap ? {
      ...state.bootstrap, access: { ...state.bootstrap.access, can_write: false },
    } : null, revision: state.revision + 1, refreshGeneration: 0 }));
  }

  private async handleAccessFailure(owner: string, error: unknown): Promise<void> {
    if (error instanceof ApiError && [401, 403, 423].includes(error.status ?? 0)) { await this.invalidate(owner); }
  }

  async refreshSnapshot(owner: string, client: MobileClient): Promise<AccountState> {
    this.clientFor(owner, client);
    let revision = 0;
    let generation = 0;
    let acknowledged = new Set<string>();
    await this.store.update(owner, state => {
      revision = state.revision;
      generation = (state.refreshGeneration ?? 0) + 1;
      acknowledged = new Set(state.queue.filter(entry => entry.status === 'confirmed').map(entry => entry.id));
      return { ...state, refreshGeneration: generation };
    });
    try {
      const bootstrap = await client.bootstrap();
      verifyBootstrap(bootstrap, owner, client.baseURL);
      const captured = await client.snapshot();
      verifySnapshot(captured, owner, client.baseURL);
      const snapshot = copy(captured);
      snapshot.clockOffsetMs = Date.parse(snapshot.captured_at) - this.now();
      const updated = await this.store.update(owner, state => {
        if (state.owner !== owner || state.revision !== revision || state.refreshGeneration !== generation) {
          throw new Error('The saved account or another catalog refresh changed. Refresh the current account again.');
        }
        if (state.snapshot && Date.parse(state.snapshot.captured_at) > Date.parse(snapshot.captured_at)) {
          throw new Error('A newer catalog is already saved.');
        }
        return { ...state, bootstrap: copy(bootstrap), snapshot, queue: state.queue.map(entry =>
          entry.status === 'confirmed' && acknowledged.has(entry.id) ? { ...entry, stockAcknowledged: true } : entry) };
      });
      return copy(updated);
    } catch (error) { await this.handleAccessFailure(owner, error); throw error; }
  }

  private checkoutState(state: AccountState, input: OnlineInput, offline: boolean): { snapshot: Snapshot; totals: Quote; paid: number } {
    const snapshot = state.snapshot;
    if (!isSnapshotFresh(snapshot, this.now()) || !snapshot.can_write || !ROLES.includes(snapshot.user.role)
      || !state.bootstrap?.user.is_active || !state.bootstrap.access.can_write
      || !state.bootstrap.abilities.includes('sales:write')) {
      throw new Error('Reconnect and refresh this company account before recording a cash sale.');
    }
    if (input.snapshotPermit !== undefined && input.snapshotPermit !== snapshot.permit) {
      throw new Error('The saved catalog changed. Review the cart again.');
    }
    if (state.pendingAction) { throw new Error('A stock or cash-shift action still needs confirmation. Recover its original request before checkout.'); }
    if (offline && state.queue.some(entry => entry.kind === 'online' && entry.status !== 'confirmed')) {
      throw new Error('An online checkout still needs confirmation. Recover its original request before recording an offline sale.');
    }
    if (remainingEntries(state).length >= MAX_PENDING) { throw new Error('Sync the queued sales before recording more.'); }
    if (!validId(input.locationId) || !snapshot.locations.some(location => location.id === input.locationId)) {
      throw new Error('Choose a location in this company.');
    }
    const totals = quote(snapshot, input.items, input.tier, input.discountPercent);
    for (const item of input.items) {
      const product = snapshot.products.find(row => row.id === item.product_id)!;
      if (item.quantity > available(product, input.locationId, state)) { throw new Error('The saved available stock cannot cover this cart. Reconnect and review stock.'); }
    }
    const paid = cents(input.cashAmount);
    if (paid < totals.total_cents) { throw new Error('Enter enough cash received to cover this sale.'); }
    return { snapshot, totals, paid };
  }

  private entry(state: AccountState, input: OnlineInput, kind: QueueEntry['kind']): QueueEntry {
    const { snapshot, totals, paid } = this.checkoutState(state, input, kind === 'offline');
    const id = this.id();
    if (!/^[A-Za-z0-9_-]{8,80}$/.test(id)) { throw new Error('The secure request identifier is invalid.'); }
    const recorded = new Date(Math.floor(serverNow(snapshot, this.now()) / 1000) * 1000).toISOString();
    const payload: SalePayload = {
      location_id: input.locationId, price_tier: input.tier, items: copy(input.items), discount_percent: input.discountPercent,
      tax_percent: snapshot.settings.tax_percent, payments: [{ method: 'cash', amount: paid / 100 }], idempotency_key: `${kind}-${id}`,
    };
    const offlinePayload: OfflineSalePayload = { ...payload, permit: snapshot.permit, recorded_at: recorded,
      expected: { items: totals.items.map(({ product_id, unit_price_cents }) => ({ product_id, unit_price_cents })),
        subtotal_cents: totals.subtotal_cents, discount_cents: totals.discount_cents, tax_cents: totals.tax_cents,
        total_cents: totals.total_cents, cash_session_id: snapshot.cash_sessions.find(session => session.location_id === input.locationId)?.id ?? null } };
    return { id, owner: state.owner, kind, status: 'pending', createdAt: recorded,
      provisionalNumber: `${kind === 'offline' ? 'OFF' : 'PENDING'}-${id.slice(0, 8).toUpperCase()}`,
      company: snapshot.tenant.name, location: snapshot.locations.find(location => location.id === input.locationId)!.name,
      payload: kind === 'offline' ? offlinePayload : payload, quote: totals, stockAcknowledged: false };
  }

  async enqueueOffline(owner: string, input: EnqueueInput): Promise<QueueEntry> {
    if (!input.snapshotPermit) { throw new Error('Review the saved catalog before recording this sale.'); }
    let entry: QueueEntry | undefined;
    await this.store.update(owner, state => {
      if (state.owner !== owner) { throw new Error('The selected company account changed.'); }
      entry = this.entry(state, copy(input), 'offline');
      if (state.queue.some(existing => existing.id === entry!.id)) { throw new Error('A request already uses this identifier.'); }
      return { ...state, queue: [...state.queue, entry] };
    });
    return copy(entry!);
  }

  async checkoutOnline(owner: string, input: OnlineInput, client: MobileClient): Promise<QueueEntry> {
    this.clientFor(owner, client);
    return this.serial(owner, async () => {
      let bootstrap: Bootstrap;
      try {
        bootstrap = await client.bootstrap();
        verifyBootstrap(bootstrap, owner, client.baseURL);
      } catch (error) {
        await this.handleAccessFailure(owner, error);
        throw error;
      }
      let saved: QueueEntry | undefined;
      await this.store.update(owner, state => {
        if (state.queue.some(entry => entry.kind === 'online' && entry.status !== 'confirmed')) {
          throw new Error('An online checkout still needs confirmation. Retry its original request first.');
        }
        saved = this.entry({ ...state, bootstrap }, copy(input), 'online');
        return { ...state, bootstrap: copy(bootstrap), queue: [...state.queue, saved] };
      });
      try { return await this.send(owner, saved!, client); } catch (error) {
        await this.recordFailure(owner, saved!, error);
        throw error;
      }
    });
  }

  private async send(owner: string, entry: QueueEntry, client: MobileClient): Promise<QueueEntry> {
    const payload = copy(entry.payload);
    const receipt = entry.kind === 'offline'
      ? await client.createOfflineSale(payload as OfflineSalePayload)
      : await client.createSale(payload);
    verifyReceipt(receipt, entry);
    let result: QueueEntry | undefined;
    await this.store.update(owner, state => ({ ...state, queue: state.queue.map(current => {
      if (current.id !== entry.id) { return current; }
      if (current.owner !== owner || JSON.stringify(current.payload) !== JSON.stringify(entry.payload)) {
        throw new Error('The protected request changed. Keep the original request for recovery.');
      }
      const changedPrice = entry.kind === 'online' && cents(receipt.total) !== entry.quote.total_cents;
      result = { ...current, status: 'confirmed', stockAcknowledged: current.status === 'confirmed' ? current.stockAcknowledged : false,
        receipt: copy(receipt), error: changedPrice ? 'The server confirmed current prices. Review the final receipt total and change.' : undefined };
      return result;
    }) }));
    if (!result) { throw new Error('The confirmed request is missing from the device queue. Keep its original key for recovery.'); }
    return copy(result);
  }

  private async recordFailure(owner: string, entry: QueueEntry, error: unknown): Promise<void> {
    await this.handleAccessFailure(owner, error);
    const conflict = error instanceof ApiError && [404, 409, 422].includes(error.status ?? 0);
    const message = error instanceof Error ? error.message : 'The original sale still needs confirmation.';
    await this.store.update(owner, state => ({ ...state, queue: state.queue.map(current => current.id === entry.id && current.status !== 'confirmed'
      ? { ...current, status: conflict ? 'conflict' : current.status, error: message } : current) }));
  }

  async synchronize(owner: string, client: MobileClient): Promise<SyncResult> {
    this.clientFor(owner, client);
    return this.serial(owner, async () => {
      let confirmed = 0;
      let conflicts = 0;
      try {
        const before = await this.state(owner);
        const revision = before.revision;
        const bootstrap = await client.bootstrap();
        verifyBootstrap(bootstrap, owner, client.baseURL);
        await this.store.update(owner, state => {
          if (state.revision !== revision) { throw new Error('The saved account changed. Sign in before synchronization.'); }
          return { ...state, bootstrap: copy(bootstrap) };
        });
        const pending = remainingEntries(await this.state(owner)).sort((a, b) => a.createdAt.localeCompare(b.createdAt));
        for (const entry of pending) {
          if ((await this.state(owner)).revision !== revision) { throw new Error('The saved account changed. Recover its requests after signing in again.'); }
          try { await this.send(owner, entry, client); confirmed++; } catch (error) {
            await this.recordFailure(owner, entry, error);
            if (error instanceof ApiError && [404, 409, 422].includes(error.status ?? 0)) { conflicts++; continue; }
            throw error;
          }
        }
        await this.refreshSnapshot(owner, client);
        return { confirmed, conflicts, state: await this.state(owner) };
      } catch (error) { await this.handleAccessFailure(owner, error); throw error; }
    });
  }

  available(product: Product, locationId: number, state: AccountState): number { return available(product, locationId, state); }
  remainingEntries(state: AccountState): QueueEntry[] { return remainingEntries(state); }

  async cashShiftBlock(owner: string, sessionId: number): Promise<string | null> {
    const state = await this.state(owner);
    const pending = remainingEntries(state);
    if (pending.some(entry => entry.kind === 'offline' && (entry.payload as OfflineSalePayload).expected.cash_session_id === sessionId)) {
      return 'Sync the queued cash sales for this original shift before closing it.';
    }
    const location = state.snapshot?.cash_sessions.find(session => session.id === sessionId)?.location_id;
    if (pending.some(entry => entry.kind === 'online' && (!location || entry.payload.location_id === location))) {
      return 'Recover the original online checkout before closing this cash shift.';
    }
    return null;
  }

  async signOut(owner: string): Promise<AccountState> {
    const state = await this.store.update(owner, current => ({ ...current, bootstrap: null, snapshot: null,
      receiptBranding: undefined, queue: remainingEntries(current), revision: current.revision + 1, refreshGeneration: 0 }));
    return copy(state);
  }
}
