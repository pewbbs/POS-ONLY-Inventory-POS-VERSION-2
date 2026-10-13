/// <reference types="node" />
import assert from 'node:assert/strict';
import test from 'node:test';
import { ApiError } from '../src/core/api';
import { ownerFor } from '../src/core/offline';
import type { Bootstrap, ReceiptBranding } from '../src/core/types';
import { isReceiptLogoDataUri, loadReceiptBranding, receiptHtml, receiptLogoVersion } from '../src/runtime/receiptBranding';
import type { ReceiptView } from '../src/ui/types';

const API = 'https://posonly.example.test/api/v1';
const OWNER = ownerFor(API, 1, 2);
const VERSION = '0123456789abcdef';
const PNG = new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10, 0, 1, 2, 3]);
const DATA_URI = `data:image/png;base64,${Buffer.from(PNG).toString('base64')}`;
const now = () => new Date('2026-10-13T08:00:00Z');

function bootstrap(reference: string | null = `/api/v1/settings/receipt-logo?v=${VERSION}`): Bootstrap {
  return { api_version: 1, server_time: now().toISOString(), user: { id: 2, name: 'QA administrator', email: 'qa@example.test', role: 'admin', is_active: true },
    tenant: { id: 1, name: 'QA company' }, abilities: ['catalog:read'], locations: [],
    settings: { currency: 'PHP', tax_percent: 12, receipt_footer: 'Thank you.', receipt_address: 'QA Street\nTest City', receipt_phone: '+63 QA', receipt_logo_url: reference },
    access: { can_write: true, operations_paused: false },
    offline: { cash_sales_only: true, requires_online_confirmation: true, snapshot_url: '/api/v1/offline/snapshot', sales_url: '/api/v1/offline/sales' } };
}

function options(overrides: Partial<Parameters<typeof loadReceiptBranding>[0]> = {}): Parameters<typeof loadReceiptBranding>[0] {
  return { server: API, owner: OWNER, token: 'fictitious-device-token', bootstrap: bootstrap(), now, ...overrides };
}

function response(bytes = PNG, mime = 'image/png'): Response {
  return new Response(bytes, { status: 200, headers: { 'content-type': mime } });
}

function receipt(overrides: Partial<ReceiptView> = {}): ReceiptView {
  return { number: 'OFF-QA-1', company: 'QA & Company', address: 'QA Street\nTest City', contact: '+63 QA', logoUrl: DATA_URI,
    location: 'Main shop', cashier: 'QA staff', status: 'pending', recordedAt: now().toISOString(), syncedAt: null,
    lines: [{ name: 'Test shirt', variant: 'M / Blue', quantity: 2, priceCents: 10000, totalCents: 20000 }],
    totals: { subtotalCents: 20000, discountCents: 2000, taxCents: 2160, totalCents: 20160, changeCents: 4840 },
    receivedCents: 25000, footer: 'Thank you.', message: null, ...overrides };
}

test('downloads only the fixed authenticated receipt endpoint and embeds raster bytes for offline use', async () => {
  let target: string | undefined;
  let request: RequestInit | undefined;
  const result = await loadReceiptBranding(options({ fetch: (async (url, init) => { target = String(url); request = init; return response(); }) as typeof fetch }));
  assert.equal(target, `${API}/settings/receipt-logo?v=${VERSION}`);
  assert.equal((request?.headers as Record<string, string>).Authorization, 'Bearer fictitious-device-token');
  assert.equal((request?.headers as Record<string, string>)['Cache-Control'], 'no-store');
  assert.equal((request?.headers as Record<string, string>).Pragma, 'no-cache');
  assert.equal(request?.redirect, 'error');
  assert.equal(request?.credentials, 'omit');
  assert.equal(request?.cache, 'no-store');
  assert.equal(result.logoDataUri, DATA_URI);
  assert.equal(result.owner, OWNER);
  assert.equal(result.address, 'QA Street\nTest City');
  assert.equal(result.contact, '+63 QA');
  assert.ok(isReceiptLogoDataUri(result.logoDataUri));
  assert.equal(result.fetchedAt, now().toISOString());
});

test('a same-owner same-version inline logo is reused without a network request while company text refreshes', async () => {
  const previous: ReceiptBranding = { owner: OWNER, version: VERSION, logoDataUri: DATA_URI, address: 'Old address', contact: null, footer: 'Old', fetchedAt: 'old' };
  const result = await loadReceiptBranding(options({ previous, fetch: (async () => { assert.fail('Cached logo must not require a connection.'); }) as typeof fetch }));
  assert.equal(result.logoDataUri, DATA_URI);
  assert.equal(result.address, 'QA Street\nTest City');
  assert.equal(result.footer, 'Thank you.');
});

test('removed, replaced or foreign branding cannot display an older company logo', async () => {
  const previous: ReceiptBranding = { owner: OWNER, version: VERSION, logoDataUri: DATA_URI, address: null, contact: null, footer: null, fetchedAt: 'old' };
  const removed = await loadReceiptBranding(options({ previous, bootstrap: bootstrap(null), fetch: (async () => { assert.fail('Removed logo must not be fetched.'); }) as typeof fetch }));
  assert.equal(removed.logoDataUri, null);
  const replaced = await loadReceiptBranding(options({ previous, bootstrap: bootstrap('/api/v1/settings/receipt-logo?v=fedcba9876543210'), fetch: (async () => new Response(null, { status: 503 })) as typeof fetch }));
  assert.equal(replaced.logoDataUri, null);
  const foreign = await loadReceiptBranding(options({ previous: { ...previous, owner: ownerFor(API, 7, 8) }, fetch: (async () => new Response(null, { status: 404 })) as typeof fetch }));
  assert.equal(foreign.logoDataUri, null);
});

test('untrusted settings references cannot cause a bearer credential request to another origin or route', async () => {
  for (const reference of ['https://evil.example.test/image.png', '//evil.example.test/api/v1/settings/receipt-logo?v=' + VERSION,
    '/api/v1/sales?v=' + VERSION, '/api/v1/settings/receipt-logo?v=' + VERSION + '&redirect=https://evil.example.test',
    '/api/v1/settings/receipt-logo?v=bad', 'data:image/svg+xml;base64,PHN2Zz4=']) {
    assert.equal(receiptLogoVersion(reference), null);
    const result = await loadReceiptBranding(options({ bootstrap: bootstrap(reference), fetch: (async () => { assert.fail('Untrusted logo reference must not be requested.'); }) as typeof fetch }));
    assert.equal(result.logoDataUri, null);
  }
  await assert.rejects(loadReceiptBranding(options({ owner: ownerFor(API, 3, 4), fetch: (async () => { assert.fail('Foreign account must not be requested.'); }) as typeof fetch })), /different company account/);
  await assert.rejects(loadReceiptBranding(options({ token: 'bad\r\nsecret' })), error => error instanceof ApiError && error.status === 401);
});

test('logo load refuses redirects, non-images, spoofed raster bytes and responses over the upload limit', async () => {
  const redirected = response(); Object.defineProperty(redirected, 'redirected', { value: true });
  const changed = response(); Object.defineProperty(changed, 'url', { value: 'https://evil.example.test/image.png' });
  const oversized = new Uint8Array(1024 * 1024 + 1); oversized.set(PNG);
  const headerOversized = response(); headerOversized.headers.set('content-length', String(1024 * 1024 + 1));
  for (const value of [redirected, changed, response(PNG, 'image/svg+xml'), response(new Uint8Array([60, 115, 118, 103]), 'image/png'), response(oversized), headerOversized]) {
    const result = await loadReceiptBranding(options({ fetch: (async () => value) as typeof fetch }));
    assert.equal(result.logoDataUri, null);
  }
});

test('authentication failures are preserved and an unavailable logo does not destroy valid receipt text', async () => {
  for (const status of [401, 403, 423]) {
    await assert.rejects(loadReceiptBranding(options({ fetch: (async () => new Response(null, { status })) as typeof fetch })), error => error instanceof ApiError && error.status === status);
  }
  const result = await loadReceiptBranding(options({ timeoutMs: 5, fetch: (() => new Promise<Response>(() => undefined)) as typeof fetch }));
  assert.equal(result.logoDataUri, null);
  assert.equal(result.address, 'QA Street\nTest City');
  assert.equal(result.contact, '+63 QA');
});

test('receipt HTML includes the inline logo, address, contact, original totals, variant and provisional status', () => {
  const html = receiptHtml(receipt());
  assert.ok(html.includes(`src="${DATA_URI}"`));
  assert.ok(html.includes('QA &amp; Company'));
  assert.ok(html.includes('QA Street\nTest City'));
  assert.ok(html.includes('+63 QA'));
  assert.ok(html.includes('M / Blue'));
  assert.ok(html.includes('₱201.60'));
  assert.ok(html.includes('₱250.00'));
  assert.ok(html.includes('₱48.40'));
  assert.ok(html.includes('Provisional · saved locally'));
  assert.ok(!html.includes('Server confirmed'));
  const confirmed = receiptHtml(receipt({ status: 'confirmed', syncedAt: '2026-10-13T08:01:00Z' }));
  assert.ok(confirmed.includes('Server confirmed'));
  assert.ok(confirmed.includes(`Confirmed ${new Date('2026-10-13T08:01:00Z').toLocaleString('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}`));
});

test('receipt recorded and confirmation timestamps share readable device-local time despite different source offsets', () => {
  const value = receipt({ recordedAt: '2026-10-13T08:00:00.000Z', syncedAt: '2026-10-13T16:00:00+08:00', status: 'confirmed' });
  const html = receiptHtml(value);
  const timestamps = /Recorded ([^<]+)<br>Confirmed ([^<]+)<\/p>/.exec(html);
  assert.ok(timestamps);
  assert.equal(timestamps[1], new Date(value.recordedAt).toLocaleString('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }));
  assert.equal(timestamps[1], timestamps[2]);
  assert.ok(!html.includes(value.recordedAt));
  assert.ok(!html.includes(value.syncedAt!));
  assert.equal(value.recordedAt, '2026-10-13T08:00:00.000Z');
  assert.equal(value.syncedAt, '2026-10-13T16:00:00+08:00');
});

test('invalid or missing receipt timestamps fall back safely without rendering input markup', () => {
  const html = receiptHtml(receipt({ recordedAt: '<img src=x onerror=steal()>', syncedAt: 'not a timestamp', logoUrl: null }));
  assert.ok(html.includes('Recorded Time unavailable'));
  assert.ok(html.includes('Confirmed Time unavailable'));
  assert.ok(!html.includes('<img'));
  assert.ok(!html.includes('Invalid Date'));
  const missing = receiptHtml(receipt({ recordedAt: '', syncedAt: null }));
  assert.ok(missing.includes('Recorded Time unavailable'));
  assert.ok(!missing.includes('<br>Confirmed'));
});

test('receipt omits a zero discount row but retains the exact nonzero original discount', () => {
  const original = receipt();
  const zero = receiptHtml({ ...original, totals: { ...original.totals, discountCents: 0 } });
  assert.ok(!zero.includes('<td>Discount</td>'));
  assert.ok(!zero.includes('−₱0.00'));
  const discounted = receiptHtml(original);
  assert.ok(discounted.includes('<td>Discount</td><td>−₱20.00</td>'));
  assert.equal(original.totals.discountCents, 2000);
});

test('receipt output escapes company data and excludes executable or remote image sources', () => {
  const malicious = `<img src="https://evil.example.test" onerror="steal()">`;
  for (const logoUrl of ['https://evil.example.test/logo.png', 'file:///private/logo.png', 'data:image/svg+xml;base64,PHN2Zz4=', DATA_URI + '" onerror="steal()']) {
    assert.equal(isReceiptLogoDataUri(logoUrl), false);
    const html = receiptHtml(receipt({ logoUrl, company: malicious, address: malicious, contact: malicious, footer: malicious,
      lines: [{ name: malicious, variant: malicious, quantity: 1, priceCents: 10000, totalCents: 10000 }] }));
    assert.ok(!html.includes('<img'));
    assert.ok(!html.includes('<script'));
    assert.ok(html.includes('&lt;img src=&quot;https://evil.example.test&quot; onerror=&quot;steal()&quot;&gt;'));
  }
});
