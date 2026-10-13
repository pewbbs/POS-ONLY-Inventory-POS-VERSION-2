import { ApiError } from '../core/api';
import { cents } from '../core/offline';
import type { AccountState } from '../core/types';

export type PendingAction = NonNullable<AccountState['pendingAction']>;

function record(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function identifier(value: unknown): value is number {
  return Number.isSafeInteger(value) && Number(value) > 0;
}

function quantity(value: unknown): value is number {
  return Number.isSafeInteger(value) && Number(value) >= 0;
}

function moment(value: unknown): boolean {
  return typeof value === 'string' && Number.isFinite(Date.parse(value));
}

function sameMoney(expected: unknown, received: unknown): boolean {
  return typeof expected === 'number' && typeof received === 'number' && cents(expected) === cents(received);
}

function reason(value: unknown): string | null {
  if (value === undefined || value === null) { return null; }
  if (typeof value !== 'string') { throw new Error('Invalid cash closing reason.'); }
  return value.trim() || null;
}

function cashSession(value: Record<string, unknown>): boolean {
  if (!identifier(value.id) || !identifier(value.location_id) || (value.status !== 'open' && value.status !== 'closed')
    || !moment(value.opened_at) || typeof value.opening_float !== 'number') { return false; }
  cents(value.opening_float);
  return value.status === 'open'
    ? value.closed_at === null && value.counted_cash === null
    : moment(value.closed_at) && typeof value.counted_cash === 'number' && Number.isSafeInteger(cents(value.counted_cash));
}

/** A successful response must identify the original operation before its protected request is cleared. */
export function verifyActionResponse(action: PendingAction, response: unknown): void {
  try {
    if (!record(response) || typeof action.payload.idempotency_key !== 'string'
      || action.payload.idempotency_key.length < 8 || action.payload.idempotency_key.length > 100) { throw new Error('Invalid protected operation.'); }
    if (action.kind === 'stock-adjust') {
      const productId = action.payload.product_id;
      const locationId = action.payload.location_id;
      if (action.path !== '/stock/adjust' || !identifier(productId) || !identifier(locationId)
        || response.id !== productId || !Array.isArray(response.stocks)) { throw new Error('The stock response identifies a different product.'); }
      const locations = response.stocks.filter(stock => record(stock) && stock.location_id === locationId);
      if (locations.length !== 1) { throw new Error('The stock response does not identify the original location.'); }
      const stock = locations[0] as Record<string, unknown>;
      if (!quantity(stock.quantity) || stock.on_hand !== stock.quantity || !quantity(stock.reserved_quantity)
        || stock.available_quantity !== Math.max(0, stock.quantity - stock.reserved_quantity)) { throw new Error('The stock response is inconsistent.'); }
      return;
    }
    if (!cashSession(response)) { throw new Error('The cash session response could not be verified.'); }
    if (action.kind === 'cash-open') {
      if (action.path !== '/cash-sessions/open' || !identifier(action.payload.location_id)
        || response.location_id !== action.payload.location_id || !sameMoney(action.payload.opening_float, response.opening_float)) {
        throw new Error('The opening response does not match the original cash shift.');
      }
      return;
    }
    const closePath = /^\/cash-sessions\/([1-9]\d*)\/close$/.exec(action.path);
    if (action.kind !== 'cash-close' || !closePath || !identifier(Number(closePath[1]))
      || response.id !== Number(closePath[1]) || response.status !== 'closed'
      || !sameMoney(action.payload.counted_cash, response.counted_cash)
      || response.close_reason !== reason(action.payload.reason)) { throw new Error('The closing response does not match the original cash shift and count.'); }
  } catch {
    throw new ApiError('The update response could not be verified. Keep the original request and retry it when connected.');
  }
}
