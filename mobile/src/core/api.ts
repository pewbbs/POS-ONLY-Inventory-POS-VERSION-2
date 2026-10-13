import type {
  Bootstrap, CashSession, Credentials, OfflineSalePayload, Page, Product,
  Receipt, SalePayload, Snapshot, TokenResponse,
} from './types';

export class ApiError extends Error {
  readonly status: number | null;
  readonly uncertain: boolean;
  readonly errors: Record<string, string[]>;

  constructor(message: string, status: number | null = null, errors: Record<string, string[]> = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.uncertain = status === null || status >= 500;
    this.errors = errors;
  }
}

function privateHost(host: string): boolean {
  return host === 'localhost' || host === '127.0.0.1' || host === '[::1]'
    || /^10\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(host)
    || /^192\.168\.\d{1,3}\.\d{1,3}$/.test(host)
    || /^172\.(?:1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}$/.test(host);
}

export function normalizeApiUrl(value: string, allowInsecureDevelopment = false): string {
  const url = new URL(value);
  if (url.username || url.password || url.search || url.hash
    || !['', '/', '/api/v1', '/api/v1/'].includes(url.pathname)
    || (url.protocol !== 'https:' && !(allowInsecureDevelopment && url.protocol === 'http:' && privateHost(url.hostname)))) {
    throw new Error('Use the HTTPS POSONLY server address. Local HTTP is allowed only for an explicitly enabled private test server.');
  }
  return `${url.origin}/api/v1`;
}

export interface ApiClientOptions {
  token?: string | null;
  allowInsecureDevelopment?: boolean;
  timeoutMs?: number;
  fetch?: typeof fetch;
}

function id(value: number): string {
  if (!Number.isSafeInteger(value) || value < 1) { throw new Error('Choose a valid record.'); }
  return String(value);
}

function query(values: Record<string, string | number | undefined | null>): string {
  const params = new URLSearchParams();
  Object.entries(values).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') { params.set(key, String(value)); }
  });
  const encoded = params.toString();
  return encoded ? `?${encoded}` : '';
}

export class ApiClient {
  readonly baseURL: string;
  private readonly token: string | null;
  private readonly timeoutMs: number;
  private readonly transport: typeof fetch;

  constructor(server = 'https://posonly.com', options: ApiClientOptions = {}) {
    this.baseURL = normalizeApiUrl(server, options.allowInsecureDevelopment);
    this.token = options.token ?? null;
    if (this.token && /[\r\n]/.test(this.token)) { throw new Error('The saved device credential is invalid. Sign in again.'); }
    this.timeoutMs = options.timeoutMs ?? 15000;
    if (!Number.isFinite(this.timeoutMs) || this.timeoutMs < 1 || this.timeoutMs > 60000) { throw new Error('Use a request timeout between 1 and 60000 milliseconds.'); }
    const transport = options.fetch ?? globalThis.fetch;
    this.transport = transport.bind(globalThis);
  }

  request<T>(path: string, method = 'GET', payload?: unknown): Promise<T> {
    return this.perform<T>(path, method, payload);
  }

  private async perform<T>(path: string, method = 'GET', payload?: unknown, publicRequest = false): Promise<T> {
    const target = new URL(this.baseURL + path);
    if (!['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].includes(method)
      || !path.startsWith('/') || path.startsWith('//') || target.origin !== new URL(this.baseURL).origin
      || !target.pathname.startsWith('/api/v1/')) { throw new Error('This request is outside the configured API.'); }
    if (!publicRequest && !this.token) { throw new ApiError('Sign in to your company to continue.', 401); }
    const controller = new AbortController();
    let timer: ReturnType<typeof setTimeout> | undefined;
    const timedOut = new Promise<never>((_, reject) => {
      timer = setTimeout(() => {
        controller.abort();
        reject(new ApiError('The connection timed out. Keep the original request and retry when connected.'));
      }, this.timeoutMs);
    });
    try {
      const headers: Record<string, string> = { Accept: 'application/json', 'Cache-Control': 'no-store', Pragma: 'no-cache' };
      if (!publicRequest) { headers.Authorization = `Bearer ${this.token}`; }
      if (payload !== undefined) { headers['Content-Type'] = 'application/json'; }
      const response = await Promise.race([this.transport(target.toString(), {
        method, headers, signal: controller.signal, redirect: 'error', credentials: 'omit', cache: 'no-store',
        ...(payload === undefined ? {} : { body: JSON.stringify(payload) }),
      }), timedOut]);
      if (response.redirected || (response.url && new URL(response.url).origin !== target.origin)) {
        throw new ApiError('The API redirected unexpectedly. The original request remains protected.');
      }
      if (response.status >= 500) {
        throw new ApiError('Connection interrupted. Keep the original request and retry when connected.', response.status);
      }
      if (response.status === 204 && response.ok) { return undefined as T; }
      let data: unknown;
      try { data = await Promise.race([response.json(), timedOut]); } catch {
        throw new ApiError('The server response could not be verified. Retry the original request.');
      }
      if (!response.ok) {
        const error = data as { message?: unknown; errors?: unknown };
        const fields = error?.errors && typeof error.errors === 'object' ? error.errors as Record<string, string[]> : {};
        const first = Object.values(fields).find(value => Array.isArray(value) && typeof value[0] === 'string')?.[0];
        throw new ApiError(first || (typeof error?.message === 'string' ? error.message : 'This request could not be completed.'), response.status, fields);
      }
      return data as T;
    } catch (error) {
      if (error instanceof ApiError) { throw error; }
      throw new ApiError('Connection interrupted. Keep the original request and retry when connected.');
    } finally { clearTimeout(timer); }
  }

  async authToken(credentials: Credentials): Promise<TokenResponse> {
    const response = await this.perform<TokenResponse>('/auth/token', 'POST', credentials, true);
    if (response?.token_type !== 'Bearer' || typeof response.token !== 'string' || !response.token
      || /[\r\n]/.test(response.token) || !Number.isFinite(Date.parse(response.expires_at))
      || !Array.isArray(response.abilities) || !response.abilities.every(ability => typeof ability === 'string')) {
      throw new ApiError('The device credential response could not be verified. Sign in again.');
    }
    return response;
  }
  revoke(): Promise<void> { return this.request('/auth/token', 'DELETE'); }
  bootstrap(): Promise<Bootstrap> { return this.request('/bootstrap'); }
  me(): Promise<Pick<Bootstrap, 'user' | 'tenant' | 'abilities'>> { return this.request('/me'); }
  snapshot(): Promise<Snapshot> { return this.request('/offline/snapshot'); }
  getProducts(filters: { q?: string; barcode?: string; sku?: string; page?: number; per_page?: number } = {}): Promise<Page<Product>> {
    return this.request(`/products${query(filters)}`);
  }
  getProduct(productId: number): Promise<Product> { return this.request(`/products/${id(productId)}`); }
  getSales(filters: { receipt?: string; location_id?: number; page?: number; from?: string; to?: string; source?: 'all' | 'pos' | 'channel' } = {}): Promise<Page<Receipt>> {
    return this.request(`/sales${query(filters)}`);
  }
  getSale(saleId: number): Promise<Receipt> { return this.request(`/sales/${id(saleId)}`); }
  createSale(payload: SalePayload): Promise<Receipt> { return this.request('/sales', 'POST', payload); }
  createOfflineSale(payload: OfflineSalePayload): Promise<Receipt> { return this.request('/offline/sales', 'POST', payload); }
  getCashSessions(locationId: number): Promise<{ current: CashSession | null; history: CashSession[] }> {
    return this.request(`/cash-sessions${query({ location_id: Number(id(locationId)) })}`);
  }
  openCashSession(payload: { location_id: number; opening_float: number; idempotency_key: string }): Promise<CashSession> {
    return this.request('/cash-sessions/open', 'POST', payload);
  }
  closeCashSession(sessionId: number, payload: { counted_cash: number; reason?: string; idempotency_key: string }): Promise<CashSession> {
    return this.request(`/cash-sessions/${id(sessionId)}/close`, 'POST', payload);
  }
  devices(): Promise<{ data: { id: number; name: string; current: boolean; created_at: string; last_used_at: string | null; expires_at: string | null }[] }> {
    return this.request('/devices');
  }
  revokeDevice(tokenId: number): Promise<void> { return this.request(`/devices/${id(tokenId)}`, 'DELETE'); }
}

export { ApiClient as APIClient };
