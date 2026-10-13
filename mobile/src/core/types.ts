export type Role = 'admin' | 'manager' | 'cashier';
export type PriceTier = 'retail' | 'wholesale';

export interface User {
  id: number;
  name: string;
  email: string;
  role: Role;
  is_active: boolean;
}

export interface Tenant { id: number; name: string }
export interface Location { id: number; name: string; type: string; address?: string | null }
export interface Stock {
  location_id: number;
  quantity: number;
  on_hand: number;
  reserved_quantity: number;
  available_quantity: number;
}

export interface Product {
  id: number;
  name: string;
  sku: string;
  barcode: string | null;
  category: string;
  price: number;
  wholesale_price: number | null;
  cost?: number;
  reorder_level: number;
  color: string | null;
  variant: string | null;
  product_family_id: number | null;
  family_name: string | null;
  option_names?: string[];
  variant_options: Record<string, string>;
  stocks: Stock[];
}

export interface Bootstrap {
  api_version: number;
  server_time: string;
  user: User;
  tenant: Tenant;
  abilities: string[];
  locations: Location[];
  settings: {
    currency: string;
    tax_percent: number;
    receipt_footer: string;
    receipt_address?: string | null;
    receipt_phone?: string | null;
    receipt_logo_url?: string | null;
  };
  access: { can_write: boolean; operations_paused: boolean };
  offline: {
    cash_sales_only: boolean;
    requires_online_confirmation: boolean;
    snapshot_url: string;
    sales_url: string;
  };
}

export interface Snapshot {
  version: number;
  captured_at: string;
  expires_at: string;
  permit: string;
  user: { id: number; role: Role };
  tenant: Tenant;
  locations: Location[];
  products: Product[];
  settings: { tax_percent: number; receipt_footer: string };
  cash_sessions: { id: number; location_id: number }[];
  can_write: boolean;
  /** Local timing metadata; never submitted to the API. */
  clockOffsetMs?: number;
}

export interface TokenResponse {
  token_type: 'Bearer';
  token: string;
  expires_at: string;
  abilities: string[];
}

export interface Credentials { email: string; password: string; device_name: string }
export interface SaleItem { product_id: number; quantity: number }
export interface Payment { method: 'cash' | 'card' | 'wallet'; amount: number; simulated?: boolean }
export interface SalePayload {
  location_id: number;
  customer_id?: number | null;
  price_tier: PriceTier;
  items: SaleItem[];
  discount_percent: number;
  tax_percent: number;
  payments: Payment[];
  idempotency_key: string;
}

export interface ExpectedQuote {
  items: { product_id: number; unit_price_cents: number }[];
  subtotal_cents: number;
  discount_cents: number;
  tax_cents: number;
  total_cents: number;
  cash_session_id: number | null;
}

export interface OfflineSalePayload extends SalePayload {
  permit: string;
  recorded_at: string;
  expected: ExpectedQuote;
}

export interface ReceiptItem {
  product_id: number;
  name: string;
  sku: string;
  quantity: number;
  unit_price: number;
  line_total: number;
  discount: number;
  tax: number;
  total: number;
  returned_quantity: number;
  returnable_quantity: number;
  returnable_amount: number;
  returned_amount: number;
}

export interface Receipt {
  id: number;
  number: string;
  location_id: number;
  customer_id: number | null;
  status: 'completed' | 'partially_returned' | 'returned';
  price_tier: PriceTier;
  cash_session_id: number | null;
  source: string;
  simulated: boolean;
  channel_id: number | null;
  channel_name: string | null;
  external_reference: string | null;
  returned_amount: number;
  net_total: number;
  cashier_name: string;
  discount_percent: number;
  tax_percent: number;
  created_at: string;
  offline: { recorded_at: string; synced_at: string | null } | null;
  returned_at: string | null;
  items: ReceiptItem[];
  returns: unknown[];
  payments: Payment[];
  subtotal: number;
  discount: number;
  tax: number;
  total: number;
  paid: number;
  change: number;
}

export interface CashSession {
  id: number;
  location_id: number;
  status: 'open' | 'closed';
  cashier_name: string;
  opened_at: string;
  closed_at: string | null;
  opening_float: number;
  cash_sales: number;
  cash_in: number;
  cash_out: number;
  expected_cash: number;
  counted_cash: number | null;
  variance: number | null;
  close_reason: string | null;
  adjustments: { id: number; direction: 'in' | 'out'; amount: number; reason: string; created_at: string }[];
}

export interface Page<T> {
  data: T[];
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
  meta: { current_page: number; last_page: number; per_page: number; total: number; [key: string]: unknown };
}

export interface Quote extends Omit<ExpectedQuote, 'cash_session_id' | 'items'> {
  items: { product_id: number; unit_price_cents: number; quantity: number; name: string; sku: string }[];
}

export interface EnqueueInput {
  locationId: number;
  items: SaleItem[];
  tier: PriceTier;
  discountPercent: number;
  cashAmount: number;
  snapshotPermit: string;
}

export type OnlineInput = Omit<EnqueueInput, 'snapshotPermit'> & { snapshotPermit?: string };
export interface QueueEntry {
  id: string;
  owner: string;
  kind: 'offline' | 'online';
  status: 'pending' | 'conflict' | 'confirmed';
  createdAt: string;
  provisionalNumber: string;
  company: string;
  location: string;
  payload: SalePayload | OfflineSalePayload;
  quote: Quote;
  stockAcknowledged: boolean;
  receipt?: Receipt;
  error?: string;
}

export interface ReceiptBranding {
  owner: string;
  version: string | null;
  logoDataUri: string | null;
  address: string | null;
  contact: string | null;
  footer: string | null;
  fetchedAt: string;
}

export interface AccountState {
  owner: string;
  bootstrap: Bootstrap | null;
  snapshot: Snapshot | null;
  queue: QueueEntry[];
  revision: number;
  refreshGeneration?: number;
  receiptBranding?: ReceiptBranding;
  /** SHA-256 fingerprints quarantine credentials until verified local sign-in initialization completes. */
  initializingCredentialHashes?: string[];
  pendingAction?: {
    kind: 'stock-adjust' | 'cash-open' | 'cash-close';
    path: string;
    payload: Record<string, unknown>;
    createdAt: string;
    error?: string;
  };
}

/** Updates must be atomic, durable, serialized, and must not retain callback-owned references. */
export interface Store {
  read(owner: string): Promise<AccountState | null>;
  update(owner: string, fn: (state: AccountState) => AccountState): Promise<AccountState>;
}

export interface MobileClient {
  readonly baseURL: string;
  bootstrap(): Promise<Bootstrap>;
  snapshot(): Promise<Snapshot>;
  createSale(payload: SalePayload): Promise<Receipt>;
  createOfflineSale(payload: OfflineSalePayload): Promise<Receipt>;
}

export interface SyncResult { confirmed: number; conflicts: number; state: AccountState }
