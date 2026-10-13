export type AppTab = 'inventory' | 'pos' | 'sales' | 'settings';
export type PriceTier = 'retail' | 'wholesale';

export interface ProductView {
  id: number;
  name: string;
  variant: string;
  sku: string;
  barcode: string | null;
  stock: number;
  priceCents: number;
  lowStock: boolean;
}

export interface CartItemView extends ProductView {
  quantity: number;
  totalCents: number;
}

export interface TotalsView {
  subtotalCents: number;
  discountCents: number;
  taxCents: number;
  totalCents: number;
  changeCents: number;
}

export interface SavedSaleView {
  id: string;
  number: string;
  status: 'pending' | 'syncing' | 'conflict' | 'confirmed';
  totalCents: number;
  itemCount: number;
  recordedAt: string;
  syncedAt: string | null;
  location: string;
  message: string | null;
}

export interface ReceiptView {
  number: string;
  company: string;
  address: string | null;
  contact: string | null;
  logoUrl: string | null;
  location: string;
  cashier: string;
  status: 'pending' | 'syncing' | 'conflict' | 'confirmed';
  recordedAt: string;
  syncedAt: string | null;
  lines: { name: string; variant: string; quantity: number; priceCents: number; totalCents: number }[];
  totals: TotalsView;
  receivedCents: number;
  footer: string | null;
  message: string | null;
}

export interface AppViewModel {
  phase: 'loading' | 'login' | 'workspace';
  tab: AppTab;
  busy: boolean;
  error: string | null;
  notice: string | null;
  isolated: boolean;
  apiOrigin: string;
  login: { email: string; password: string; deviceName: string };
  account: { company: string; name: string; email: string; role: string } | null;
  online: boolean;
  syncing: boolean;
  loginRequired: boolean;
  pendingCount: number;
  catalog: { savedAt: string | null; expiresAt: string | null; usable: boolean; productCount: number };
  locations: { id: number; name: string }[];
  locationId: number | null;
  priceTier: PriceTier;
  search: string;
  products: ProductView[];
  cart: CartItemView[];
  discount: string;
  cashReceived: string;
  totals: TotalsView | null;
  checkoutAllowed: boolean;
  checkoutReason: string | null;
  reviewOpen: boolean;
  savedSales: SavedSaleView[];
  receipt: ReceiptView | null;
  cashSession: { id: number; location: string; openingCents: number; expectedCents: number } | null;
  cashSessionKnown: boolean;
  shiftDialog: 'open' | 'close' | null;
  shiftAmount: string;
  shiftNote: string;
  closeShiftAllowed: boolean;
  closeShiftReason: string | null;
  canAdjustStock: boolean;
  stockAdjustment: { productId: number; productName: string; quantity: string; reason: string } | null;
  devices: { id: number; name: string; current: boolean; lastUsedAt: string | null }[] | null;
  pendingUpdate: string | null;
}

export interface AppActions {
  onLoginField: (field: 'email' | 'password' | 'deviceName', value: string) => void;
  onLogin: () => void;
  onTabChange: (tab: AppTab) => void;
  onSearch: (value: string) => void;
  onScan: () => void;
  onLocationChange: (id: number) => void;
  onPriceTierChange: (tier: PriceTier) => void;
  onAddProduct: (id: number) => void;
  onQuantityChange: (id: number, quantity: number) => void;
  onRemoveProduct: (id: number) => void;
  onClearCart: () => void;
  onDiscountChange: (value: string) => void;
  onCashReceivedChange: (value: string) => void;
  onReview: () => void;
  onCancelReview: () => void;
  onConfirmSale: () => void;
  onSync: () => void;
  onRefreshCatalog: () => void;
  onViewReceipt: (id: string) => void;
  onCloseReceipt: () => void;
  onPrintReceipt: () => void;
  onRetrySale: (id: string) => void;
  onSignOut: () => void;
  onRequireLogin: () => void;
  onShiftDialog: (dialog: 'open' | 'close' | null) => void;
  onShiftAmountChange: (value: string) => void;
  onShiftNoteChange: (value: string) => void;
  onSubmitShift: () => void;
  onAdjustProduct: (id: number) => void;
  onCloseAdjustment: () => void;
  onAdjustmentField: (field: 'quantity' | 'reason', value: string) => void;
  onSubmitAdjustment: () => void;
  onExportQueue: () => void;
  onLoadDevices: () => void;
  onRevokeDevice: (id: number) => void;
}

export interface AppViewProps {
  model: AppViewModel;
  actions: AppActions;
}
