import assert from 'node:assert/strict';
import test from 'node:test';
import { ApiError } from '../src/core/api';
import { verifyActionResponse, type PendingAction } from '../src/runtime/actionVerification';

const action = (kind: PendingAction['kind'], path: string, payload: Record<string, unknown>): PendingAction => ({
  kind, path, payload: { ...payload, idempotency_key: 'mobile-original-request-key' }, createdAt: '2026-10-13T08:00:00Z',
});
const opening = (): PendingAction => action('cash-open', '/cash-sessions/open', { location_id: 3, opening_float: 500 });
const closing = (): PendingAction => action('cash-close', '/cash-sessions/42/close', { counted_cash: 612, reason: '  End of shift  ' });
const adjustment = (): PendingAction => action('stock-adjust', '/stock/adjust', { product_id: 8, location_id: 3, quantity: 2, reason: 'Count correction' });
const product = () => ({ id: 8, stocks: [{ location_id: 3, quantity: 24, on_hand: 24, reserved_quantity: 4, available_quantity: 20 }] });
const shift = () => ({ id: 42, location_id: 3, status: 'open', opened_at: '2026-10-13T08:00:00Z', closed_at: null as string | null,
  opening_float: 500, counted_cash: null as number | null, close_reason: null as string | null });
const closedShift = () => ({ ...shift(), status: 'closed', closed_at: '2026-10-13T18:00:00Z', counted_cash: 612, close_reason: 'End of shift' });
const unresolved = (error: unknown): boolean => error instanceof ApiError && error.status === null && error.uncertain && /original request/.test(error.message);

test('stock confirmation identifies its original product and location even when other staff have changed the current quantity', () => {
  const request = adjustment();
  const original = JSON.stringify(request);

  verifyActionResponse(request, product());
  verifyActionResponse(request, { id: 8, stocks: [{ location_id: 3, quantity: 2, on_hand: 2, reserved_quantity: 4, available_quantity: 0 }] });

  assert.equal(JSON.stringify(request), original);
});

test('a stock response for another product or location remains unresolved with its immutable request', () => {
  const request = adjustment();
  const original = JSON.stringify(request);

  assert.throws(() => verifyActionResponse(request, { ...product(), id: 9 }), unresolved);
  assert.throws(() => verifyActionResponse(request, { id: 8, stocks: [{ ...product().stocks[0], location_id: 4 }] }), unresolved);
  assert.throws(() => verifyActionResponse(request, { id: 8, stocks: [] }), unresolved);
  assert.throws(() => verifyActionResponse(request, { id: 8, stocks: [product().stocks[0], product().stocks[0]] }), unresolved);

  assert.equal(JSON.stringify(request), original);
});

test('an inconsistent or malformed stock success cannot clear its protected operation', () => {
  const request = adjustment();

  for (const value of [
    { ...product().stocks[0], quantity: -1 }, { ...product().stocks[0], on_hand: 25 },
    { ...product().stocks[0], reserved_quantity: -1 }, { ...product().stocks[0], available_quantity: 21 },
  ]) { assert.throws(() => verifyActionResponse(request, { id: 8, stocks: [value] }), unresolved); }
  for (const value of [null, [], {}, { data: product() }]) { assert.throws(() => verifyActionResponse(request, value), unresolved); }
});

test('opening recovery accepts the original opening amount and an already closed original shift', () => {
  const request = opening();

  verifyActionResponse(request, shift());
  verifyActionResponse(request, closedShift());

  assert.equal(request.payload.idempotency_key, 'mobile-original-request-key');
});

test('an opening success for a different location or amount cannot confirm the original shift', () => {
  const request = opening();

  assert.throws(() => verifyActionResponse(request, { ...shift(), location_id: 4 }), unresolved);
  assert.throws(() => verifyActionResponse(request, { ...shift(), opening_float: 500.01 }), unresolved);
  assert.throws(() => verifyActionResponse(request, { ...shift(), id: 0 }), unresolved);
  assert.throws(() => verifyActionResponse(request, { ...shift(), opened_at: 'unknown' }), unresolved);
  assert.throws(() => verifyActionResponse(request, { ...shift(), counted_cash: 612 }), unresolved);
  assert.throws(() => verifyActionResponse(request, { ...closedShift(), status: ['closed'] }), unresolved);
});

test('closing confirmation matches the original session, exact cash count, and server-normalized reason', () => {
  const request = closing();
  const original = JSON.stringify(request);

  verifyActionResponse(request, closedShift());
  verifyActionResponse(action('cash-close', '/cash-sessions/42/close', { counted_cash: 612, reason: '  ' }), { ...closedShift(), close_reason: null });
  verifyActionResponse(action('cash-close', '/cash-sessions/42/close', { counted_cash: 0 }), { ...closedShift(), counted_cash: 0, close_reason: null });

  assert.equal(JSON.stringify(request), original);
});

test('a different session, count, reason, or still-open response leaves closing unresolved', () => {
  const request = closing();
  const original = JSON.stringify(request);

  for (const value of [
    { ...closedShift(), id: 43 }, { ...closedShift(), counted_cash: 612.01 },
    { ...closedShift(), close_reason: 'Different count' }, shift(),
    { ...closedShift(), closed_at: null }, { ...closedShift(), counted_cash: null },
    { ...closedShift(), counted_cash: 612.001 },
  ]) { assert.throws(() => verifyActionResponse(request, value), unresolved); }

  assert.equal(JSON.stringify(request), original);
});

test('an altered action path or missing original request key cannot be marked confirmed', () => {
  const request = closing();

  assert.throws(() => verifyActionResponse({ ...request, path: '/cash-sessions/43/close' }, closedShift()), unresolved);
  assert.throws(() => verifyActionResponse({ ...request, path: '/cash-sessions/42/close?counted_cash=612' }, closedShift()), unresolved);
  assert.throws(() => verifyActionResponse({ ...opening(), path: '/cash-sessions/42/close' }, shift()), unresolved);
  assert.throws(() => verifyActionResponse({ ...adjustment(), path: '/stock/transfer' }, product()), unresolved);
  assert.throws(() => verifyActionResponse({ ...request, payload: { counted_cash: 612, reason: 'End of shift' } }, closedShift()), unresolved);
});
