import { ApiError, normalizeApiUrl } from '../core/api';
import { ownerFor } from '../core/offline';
import type { Bootstrap, ReceiptBranding } from '../core/types';
import type { ReceiptView } from '../ui/types';

const MAX_LOGO_BYTES = 1024 * 1024;
const BASE64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
const allowedMime = ['image/png', 'image/jpeg', 'image/webp'] as const;
type LogoMime = typeof allowedMime[number];

export interface ReceiptBrandingOptions {
  server: string;
  owner: string;
  token: string;
  bootstrap: Bootstrap;
  previous?: ReceiptBranding;
  allowInsecureDevelopment?: boolean;
  timeoutMs?: number;
  fetch?: typeof fetch;
  now?: () => Date;
}

/** The settings reference is used only as a cache version, never as a fetch destination. */
export function receiptLogoVersion(reference: string | null | undefined): string | null {
  return /^\/api\/v1\/settings\/receipt-logo\?v=([a-f0-9]{16})$/.exec(reference ?? '')?.[1] ?? null;
}

function matchesImage(bytes: Uint8Array, mime: LogoMime): boolean {
  if (mime === 'image/png') {
    return bytes.length >= 8 && [137, 80, 78, 71, 13, 10, 26, 10].every((value, index) => bytes[index] === value);
  }
  if (mime === 'image/jpeg') {
    return bytes.length >= 3 && bytes[0] === 255 && bytes[1] === 216 && bytes[2] === 255;
  }
  return bytes.length >= 12 && [82, 73, 70, 70].every((value, index) => bytes[index] === value)
    && [87, 69, 66, 80].every((value, index) => bytes[index + 8] === value);
}

function encodeBase64(bytes: Uint8Array): string {
  const chunks: string[] = [];
  for (let start = 0; start < bytes.length; start += 3072) {
    let chunk = '';
    for (let index = start; index < Math.min(bytes.length, start + 3072); index += 3) {
      const first = bytes[index];
      const second = bytes[index + 1];
      const third = bytes[index + 2];
      chunk += BASE64[first >> 2] + BASE64[((first & 3) << 4) | ((second ?? 0) >> 4)]
        + (second === undefined ? '=' : BASE64[((second & 15) << 2) | ((third ?? 0) >> 6)])
        + (third === undefined ? '=' : BASE64[third & 63]);
    }
    chunks.push(chunk);
  }
  return chunks.join('');
}

/** Only small inline raster images can be rendered or printed; remote and executable sources are excluded. */
export function isReceiptLogoDataUri(value: unknown): value is string {
  if (typeof value !== 'string' || value.length > Math.ceil(MAX_LOGO_BYTES / 3) * 4 + 40) {
    return false;
  }
  const match = /^data:(image\/(?:png|jpeg|webp));base64,([A-Za-z0-9+/]+={0,2})$/.exec(value);
  if (!match || match[2].length % 4 !== 0) {
    return false;
  }
  const encoded = match[2];
  const padding = encoded.endsWith('==') ? 2 : encoded.endsWith('=') ? 1 : 0;
  if (encoded.length / 4 * 3 - padding > MAX_LOGO_BYTES) {
    return false;
  }
  const bytes: number[] = [];
  for (let index = 0; index < Math.min(encoded.length, 16); index += 4) {
    const first = BASE64.indexOf(encoded[index]);
    const second = BASE64.indexOf(encoded[index + 1]);
    const third = BASE64.indexOf(encoded[index + 2]);
    const fourth = BASE64.indexOf(encoded[index + 3]);
    bytes.push((first << 2) | (second >> 4));
    if (third >= 0) { bytes.push(((second & 15) << 4) | (third >> 2)); }
    if (fourth >= 0) { bytes.push(((third & 3) << 6) | fourth); }
  }
  return matchesImage(new Uint8Array(bytes), match[1] as LogoMime);
}

export async function loadReceiptBranding(options: ReceiptBrandingOptions): Promise<ReceiptBranding> {
  const base = normalizeApiUrl(options.server, options.allowInsecureDevelopment);
  if (ownerFor(base, options.bootstrap.tenant.id, options.bootstrap.user.id) !== options.owner) {
    throw new Error('Receipt branding belongs to a different company account.');
  }
  if (!options.token || /[\r\n]/.test(options.token)) {
    throw new ApiError('Sign in again to load your company receipt logo.', 401);
  }
  const timeout = options.timeoutMs ?? 15000;
  if (!Number.isFinite(timeout) || timeout < 1 || timeout > 60000) {
    throw new Error('Use a logo request timeout between 1 and 60000 milliseconds.');
  }
  const version = receiptLogoVersion(options.bootstrap.settings.receipt_logo_url);
  const branding: ReceiptBranding = {
    owner: options.owner,
    version,
    logoDataUri: null,
    address: options.bootstrap.settings.receipt_address ?? null,
    contact: options.bootstrap.settings.receipt_phone ?? null,
    footer: options.bootstrap.settings.receipt_footer ?? null,
    fetchedAt: (options.now ?? (() => new Date()))().toISOString(),
  };
  if (!version) { return branding; }
  const previous = options.previous;
  if (previous?.owner === options.owner && previous.version === version && isReceiptLogoDataUri(previous.logoDataUri)) {
    return { ...branding, logoDataUri: previous.logoDataUri };
  }

  const target = `${base}/settings/receipt-logo?v=${version}`;
  const controller = new AbortController();
  let timer: ReturnType<typeof setTimeout> | undefined;
  const timedOut = new Promise<never>((_, reject) => {
    timer = setTimeout(() => { controller.abort(); reject(new ApiError('The company receipt logo could not be loaded.')); }, timeout);
  });
  try {
    const transport = (options.fetch ?? globalThis.fetch).bind(globalThis);
    const response = await Promise.race([transport(target, {
      headers: { Accept: 'image/png, image/jpeg, image/webp', Authorization: `Bearer ${options.token}`, 'Cache-Control': 'no-store', Pragma: 'no-cache' },
      method: 'GET', redirect: 'error', credentials: 'omit', cache: 'no-store', signal: controller.signal,
    }), timedOut]);
    if (response.redirected || (response.url && response.url !== target)) { return branding; }
    if ([401, 403, 423].includes(response.status)) {
      throw new ApiError('Sign in to the original company account to load its receipt logo.', response.status);
    }
    if (!response.ok) { return branding; }
    const mime = response.headers.get('content-type')?.split(';')[0].trim().toLowerCase() as LogoMime;
    if (!allowedMime.includes(mime)) { return branding; }
    const contentLength = Number(response.headers.get('content-length'));
    if (!Number.isFinite(contentLength) || contentLength < 0 || contentLength > MAX_LOGO_BYTES) { return branding; }
    const bytes = new Uint8Array(await Promise.race([response.arrayBuffer(), timedOut]));
    if (bytes.length > MAX_LOGO_BYTES || !matchesImage(bytes, mime)) { return branding; }
    return { ...branding, logoDataUri: `data:${mime};base64,${encodeBase64(bytes)}` };
  } catch (error) {
    if (error instanceof ApiError && [401, 403, 423].includes(error.status ?? 0)) { throw error; }
    return branding;
  } finally { clearTimeout(timer); }
}

const escape = (value: string | number | null) => String(value ?? '').replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]!));
const money = (value: number) => `₱${(value / 100).toFixed(2)}`;

function receiptDate(value: string | null): string {
  if (!value) { return 'Time unavailable'; }
  const date = new Date(value);
  if (!Number.isFinite(date.getTime())) { return 'Time unavailable'; }
  return date.toLocaleString('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

/** HTML has no external assets or scripts, so company branding remains printable without a connection. */
export function receiptHtml(receipt: ReceiptView): string {
  const logo = isReceiptLogoDataUri(receipt.logoUrl) ? `<img class="logo" src="${receipt.logoUrl}" alt="${escape(receipt.company)} logo">` : '';
  const details = [receipt.address, receipt.contact].filter(Boolean).map(detail => `<p class="note detail">${escape(detail ?? null)}</p>`).join('');
  const discount = receipt.totals.discountCents > 0 ? `<tr><td>Discount</td><td>−${money(receipt.totals.discountCents)}</td></tr>` : '';
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:14px -apple-system,Arial;color:#191918;padding:24px;max-width:360px;margin:auto;overflow-wrap:anywhere}h1{font-size:22px;text-align:center}.logo{display:block;max-width:150px;max-height:90px;margin:0 auto 12px;object-fit:contain}p{line-height:1.6}table{width:100%;border-collapse:collapse}td{padding:10px 0;border-bottom:1px solid #eee;vertical-align:top}td:last-child{text-align:right;white-space:nowrap;padding-left:12px}.total{font-size:18px;font-weight:bold}.note{text-align:center;font-size:12px;color:#666}.detail{white-space:pre-line;margin:4px 0}@media print{body{padding:12px}.receipt-line{break-inside:avoid}}</style></head><body>${logo}<h1>${escape(receipt.company)}</h1>${details}<p><strong>${escape(receipt.number)}</strong><br>${escape(receipt.location)}<br>${escape(receipt.cashier)}<br>${receipt.status === 'confirmed' ? 'Server confirmed' : receipt.status === 'conflict' ? 'Provisional · needs review' : 'Provisional · saved locally'}<br>Recorded ${escape(receiptDate(receipt.recordedAt))}${receipt.syncedAt ? `<br>Confirmed ${escape(receiptDate(receipt.syncedAt))}` : ''}</p><table>${receipt.lines.map(line => `<tr class="receipt-line"><td>${escape(line.name)}${line.variant ? `<br>${escape(line.variant)}` : ''}<br>${escape(line.quantity)} × ${money(line.priceCents)}</td><td>${money(line.totalCents)}</td></tr>`).join('')}<tr><td>Subtotal</td><td>${money(receipt.totals.subtotalCents)}</td></tr>${discount}<tr><td>Tax</td><td>${money(receipt.totals.taxCents)}</td></tr><tr class="total"><td>Total</td><td>${money(receipt.totals.totalCents)}</td></tr><tr><td>Cash received</td><td>${money(receipt.receivedCents)}</td></tr><tr><td>Change</td><td>${money(receipt.totals.changeCents)}</td></tr></table><p class="note">${escape(receipt.footer)}<br>Payment record · not a fiscal invoice.${receipt.message ? `<br>${escape(receipt.message)}` : ''}</p></body></html>`;
}
