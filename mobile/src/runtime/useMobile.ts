import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState, Platform } from 'react-native';
import * as Crypto from 'expo-crypto';
import * as Network from 'expo-network';
import { ApiClient, ApiError } from '../core/api';
import { OfflineEngine, available, cents, isSnapshotFresh, ownerFor, quote, remainingEntries } from '../core/offline';
import type { AccountState, Bootstrap, CashSession, QueueEntry, SaleItem } from '../core/types';
import { accountStore } from '../storage/store';
import { sessionVault } from '../storage/vault';
import type { DeviceSession } from '../storage/shared';
import { apiUrl, assertClientEnvironment, isolated } from '../config';
import type { AppActions, AppTab, AppViewModel, PriceTier, ReceiptView, TotalsView } from '../ui/types';
import { isReceiptLogoDataUri, loadReceiptBranding } from './receiptBranding';
import { verifyActionResponse } from './actionVerification';
import { appFetch } from './transport';

const engine = new OfflineEngine(accountStore, { id: () => Crypto.randomUUID() });
const initializingHashes = (state: AccountState | null) => state?.initializingCredentialHashes ?? [];
const credentialHash = (token: string) => Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, token);
const sameCredential = (first: DeviceSession | null, second: DeviceSession) => first?.apiUrl === second.apiUrl
  && first.owner === second.owner && first.token === second.token && first.expiresAt === second.expiresAt;
const toMoney = (text: string): number => {
  if (!/^\d+(?:\.\d{1,2})?$/.test(text.trim())) { throw new Error('Enter an amount with up to two decimal places.'); }
  return cents(Number(text.trim())) / 100;
};
const clientFor = (session: DeviceSession) => new ApiClient(apiUrl, { token: session.token, allowInsecureDevelopment: isolated, fetch: appFetch });
const totalsView = (entry: QueueEntry): TotalsView => ({
  subtotalCents: entry.quote.subtotal_cents, discountCents: entry.quote.discount_cents,
  taxCents: entry.quote.tax_cents, totalCents: entry.quote.total_cents,
  changeCents: cents(entry.payload.payments[0].amount) - entry.quote.total_cents,
});

export function useMobile(tab: AppTab, navigate: (tab: AppTab) => void) {
  const [phase, setPhase] = useState<AppViewModel['phase']>('loading');
  const [session, setSession] = useState<DeviceSession | null>(null);
  const sessionRef = useRef<DeviceSession | null>(null);
  const [account, setAccount] = useState<AccountState | null>(null);
  const accountRef = useRef<AccountState | null>(null);
  const [login, setLogin] = useState({ email: '', password: '', deviceName: `POSONLY ${Platform.OS === 'ios' ? 'iPhone' : Platform.OS === 'android' ? 'Android' : 'isolated preview'}` });
  const [online, setOnline] = useState(true);
  const [busy, setBusy] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const working = useRef(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [locationId, setLocation] = useState<number | null>(null);
  const [tier, setTier] = useState<PriceTier>('retail');
  const [search, setSearch] = useState('');
  const [cart, setCart] = useState<SaleItem[]>([]);
  const [discount, setDiscount] = useState('0');
  const [cashReceived, setCashReceived] = useState('');
  const [reviewOpen, setReview] = useState(false);
  const reviewRequest = useRef<Parameters<typeof engine.enqueueOffline>[1] | null>(null);
  const hasCart = useRef(false);
  const [receiptId, setReceiptId] = useState<string | null>(null);
  const [cashSession, setCashSession] = useState<CashSession | null>(null);
  const [cashSessionKnown, setCashSessionKnown] = useState(false);
  const [shiftDialog, setShiftDialog] = useState<'open' | 'close' | null>(null);
  const [shiftAmount, setShiftAmount] = useState('');
  const [shiftNote, setShiftNote] = useState('');
  const [stockAdjustment, setStockAdjustment] = useState<AppViewModel['stockAdjustment']>(null);
  const [scanOpen, setScanOpen] = useState(false);
  const [clock, setClock] = useState(Date.now());
  const [devices, setDevices] = useState<AppViewModel['devices']>(null);

  const acceptSession = useCallback((value: DeviceSession | null) => { sessionRef.current = value; setSession(value); }, []);
  const acceptAccount = useCallback((value: AccountState | null) => { accountRef.current = value; setAccount(value); setClock(Date.now()); }, []);
  const reload = useCallback(async (owner: string) => {
    const value = await accountStore.read(owner);
    if (sessionRef.current?.owner === owner) { acceptAccount(value); }
    return value;
  }, [acceptAccount]);

  const handleFailure = useCallback(async (failure: unknown, current: DeviceSession | null = sessionRef.current) => {
    const message = failure instanceof Error ? failure.message : 'This request could not be completed.';
    setError(message);
    if (failure instanceof ApiError) {
      if (failure.status === null || failure.status >= 500) { setOnline(false); }
    }
    if (failure instanceof ApiError && current) {
      if ([401, 403, 423].includes(failure.status || 0)) {
        await accountStore.update(current.owner, value => ({ ...value, snapshot: null, revision: value.revision + 1,
          bootstrap: value.bootstrap ? { ...value.bootstrap, access: { ...value.bootstrap.access, can_write: false } } : null }));
        if (failure.status === 401) {
          const expired = { ...current, token: null };
          await sessionVault.write(expired); acceptSession(expired);
        }
      }
      await reload(current.owner);
    }
  }, [acceptSession, reload]);

  const run = useCallback(async (task: () => Promise<void>, failureSession?: () => DeviceSession | null) => {
    if (working.current) { return; }
    working.current = true; setBusy(true); setError(null);
    try { await task(); } catch (failure) { await handleFailure(failure, failureSession ? failureSession() : sessionRef.current); }
    finally { working.current = false; setBusy(false); }
  }, [handleFailure]);

  const refresh = useCallback(async (current: DeviceSession, sync = true) => {
    if (!current.token || Date.parse(current.expiresAt) <= Date.now()) { throw new ApiError('Sign in again to confirm saved sales. Their original requests are still on this device.', 401); }
    const client = clientFor(current);
    const bootstrap = await client.bootstrap();
    if (ownerFor(apiUrl, bootstrap.tenant.id, bootstrap.user.id) !== current.owner) { throw new ApiError('Sign in to the original company account to recover these requests.', 403); }
    await accountStore.update(current.owner, value => ({ ...value, bootstrap }));
    const value = await accountStore.read(current.owner);
    const receiptBranding = await loadReceiptBranding({ server: apiUrl, owner: current.owner, token: current.token, bootstrap, previous: value?.receiptBranding, allowInsecureDevelopment: isolated, fetch: appFetch });
    if (sessionRef.current?.owner !== current.owner || sessionRef.current?.token !== current.token) { return; }
    await accountStore.update(current.owner, state => state.bootstrap?.tenant.id === bootstrap.tenant.id
      && state.bootstrap.settings.receipt_logo_url === bootstrap.settings.receipt_logo_url ? { ...state, receiptBranding } : state);
    if (sync && value && remainingEntries(value).length && !value.pendingAction) {
      setSyncing(true);
      try {
        const result = await engine.synchronize(current.owner, client);
        setNotice(`${result.confirmed} saved sales confirmed; ${result.conflicts} need review. Original amounts were kept.`);
      } finally { setSyncing(false); }
    } else { await engine.refreshSnapshot(current.owner, client); }
    setOnline(true); await reload(current.owner);
  }, [reload]);

  useEffect(() => {
    let live = true;
    (async () => {
      try {
        assertClientEnvironment();
        const previous = await sessionVault.read();
        if (!live) { return; }
        if (!previous || previous.apiUrl !== apiUrl) { setPhase('login'); return; }
        const saved = await accountStore.read(previous.owner);
        const incompleteCredential = previous.token && initializingHashes(saved).includes(await credentialHash(previous.token));
        if (!saved?.bootstrap || incompleteCredential) {
          acceptSession(null); acceptAccount(null); setPhase('login');
          if (incompleteCredential) { setError('The previous sign-in setup could not be verified. Sign in again; your saved sales are retained.'); }
          await sessionVault.remove(); return;
        }
        acceptSession(previous);
        acceptAccount(saved); setLocation(saved?.snapshot?.locations[0]?.id || saved?.bootstrap?.locations[0]?.id || null);
        setPhase(saved?.bootstrap ? 'workspace' : 'login');
        if (previous.token) { await run(() => refresh(previous)); }
      } catch (failure) { if (live) { setError(failure instanceof Error ? failure.message : 'The app could not read saved data.'); setPhase('login'); } }
    })();
    return () => { live = false; };
  }, [acceptAccount, acceptSession, refresh, run]);

  const confirmedSaleCount = account?.queue.filter(entry => entry.status === 'confirmed').length || 0;
  useEffect(() => {
    setCashSessionKnown(false);
    if (!locationId || !session?.token || phase !== 'workspace' || !online) { setCashSession(null); return; }
    let live = true;
    clientFor(session).getCashSessions(locationId).then(result => { if (live) { setCashSession(result.current); setCashSessionKnown(true); } }).catch(() => { if (live) { setCashSession(null); setCashSessionKnown(false); } });
    return () => { live = false; };
  }, [locationId, online, phase, session, account?.revision, account?.snapshot?.captured_at, confirmedSaleCount]);

  useEffect(() => { hasCart.current = cart.length > 0 || reviewOpen; }, [cart, reviewOpen]);

  useEffect(() => {
    const reconnect = () => {
      const current = sessionRef.current;
      if (current?.token && !working.current && !hasCart.current && AppState.currentState !== 'background') { void run(() => refresh(current)); }
    };
    const subscription = Network.addNetworkStateListener(value => { const connected = value.isConnected !== false && value.isInternetReachable !== false; setOnline(connected); if (connected) { reconnect(); } });
    const foreground = AppState.addEventListener('change', value => { if (value === 'active') { reconnect(); } });
    const timer = setInterval(() => {
      setClock(Date.now());
      const value = accountRef.current;
      if (value && (remainingEntries(value).length || !value.snapshot || !isSnapshotFresh(value.snapshot))) { reconnect(); }
    }, 15000);
    return () => { subscription.remove(); foreground.remove(); clearInterval(timer); };
  }, [refresh, run]);

  const snapshot = account?.snapshot || null;
  const pending = account ? remainingEntries(account) : [];
  const loginRequired = !session?.token || Date.parse(session.expiresAt) <= clock;
  const usable = isSnapshotFresh(snapshot, clock) && !!snapshot.can_write && !loginRequired;
  const products = snapshot?.products || [];
  const price = (product: typeof products[number]) => tier === 'retail' ? product.price : product.wholesale_price;
  let totals: TotalsView | null = null;
  let quoteError: string | null = null;
  try {
    if (snapshot && cart.length) {
      const result = quote(snapshot, cart, tier, Number(discount));
      totals = { subtotalCents: result.subtotal_cents, discountCents: result.discount_cents, taxCents: result.tax_cents, totalCents: result.total_cents, changeCents: cashReceived ? Math.max(0, cents(Number(cashReceived)) - result.total_cents) : 0 };
    }
  } catch (failure) { quoteError = failure instanceof Error ? failure.message : 'Review the cart.'; }

  const checkoutReason = loginRequired ? 'Sign in again before recording another sale.' : !snapshot ? 'Save this account’s catalog while connected.' : !usable ? 'The catalog expired or account writes are paused. Reconnect and refresh.' : account?.pendingAction ? 'Check the original stock or cash-session update before another checkout.' : pending.some(entry => entry.kind === 'online') ? 'Confirm the original online checkout before recording another sale.' : quoteError;

  const showReceipt = useCallback((entry: QueueEntry, state: AccountState | null): ReceiptView => {
    const receipt = entry.receipt;
    return {
      number: receipt?.number || entry.provisionalNumber, company: entry.company,
      address: state?.receiptBranding?.address || state?.bootstrap?.settings.receipt_address || null, contact: state?.receiptBranding?.contact || state?.bootstrap?.settings.receipt_phone || null,
      logoUrl: state?.receiptBranding?.owner === entry.owner && isReceiptLogoDataUri(state.receiptBranding.logoDataUri) ? state.receiptBranding.logoDataUri : null,
      location: entry.location, cashier: receipt?.cashier_name || state?.bootstrap?.user.name || '', status: entry.status,
      recordedAt: entry.createdAt, syncedAt: receipt?.offline?.synced_at || receipt?.created_at || null,
      lines: entry.quote.items.map(item => ({ name: item.name, variant: item.sku, quantity: item.quantity, priceCents: item.unit_price_cents, totalCents: item.unit_price_cents * item.quantity })),
      totals: totalsView(entry), receivedCents: cents(entry.payload.payments[0].amount), footer: state?.snapshot?.settings.receipt_footer || state?.bootstrap?.settings.receipt_footer || null,
      message: entry.error || (entry.status === 'confirmed' ? null : 'Saved on this device. Central stock and the drawer update only after confirmation.'),
    };
  }, []);

  const performAction = async (current: DeviceSession) => {
    const value = await accountStore.read(current.owner);
    const action = value?.pendingAction;
    if (!action) { return; }
    if (action.kind === 'cash-close') {
      const shiftId = Number(action.path.split('/')[2]);
      const block = await engine.cashShiftBlock(current.owner, shiftId);
      if (block) { throw new Error(block); }
    }
    try { verifyActionResponse(action, await clientFor(current).request(action.path, 'POST', action.payload)); }
    catch (failure) {
      if (failure instanceof ApiError && failure.status === 422) { await accountStore.update(current.owner, state => ({ ...state, pendingAction: undefined })); }
      else { await accountStore.update(current.owner, state => ({ ...state, pendingAction: state.pendingAction ? { ...state.pendingAction, error: failure instanceof Error ? failure.message : 'Check update status.' } : undefined })); }
      throw failure;
    }
    await accountStore.update(current.owner, state => ({ ...state, pendingAction: undefined, revision: state.revision + 1 }));
    setShiftDialog(null); setStockAdjustment(null);
    setNotice('The original update was confirmed once.');
    await reload(current.owner);
    try { await refresh(current, false); }
    catch (failure) { await handleFailure(failure); setNotice('The update is confirmed. Reconnect to refresh the catalog; do not submit it again.'); }
  };

  const saveAction = async (kind: NonNullable<AccountState['pendingAction']>['kind'], path: string, payload: Record<string, unknown>) => {
    const current = sessionRef.current;
    if (!current?.token || !online) { throw new Error('Connect and sign in before updating stock or a cash session.'); }
    const value = await accountStore.read(current.owner);
    if (value?.pendingAction) { throw new Error('An earlier update is awaiting confirmation. Open Settings and retry that original update before starting another.'); }
    if (value && remainingEntries(value).length) { throw new Error('Sync or reconcile all saved sales before changing stock or cash sessions.'); }
    await accountStore.update(current.owner, state => ({ ...state, pendingAction: { kind, path, payload: { ...payload, idempotency_key: `mobile-${Crypto.randomUUID()}` }, createdAt: new Date().toISOString() } }));
    await performAction(current);
  };

  const actions: AppActions = {
    onLoginField: (field, value) => setLogin(previous => ({ ...previous, [field]: value })),
    onLogin: () => {
      let established = false;
      void run(async () => {
        assertClientEnvironment();
        let previous = await sessionVault.read();
        if (previous) {
          const previousAccount = await accountStore.read(previous.owner);
          if (!previousAccount?.bootstrap || (previous.token && initializingHashes(previousAccount).includes(await credentialHash(previous.token)))) { previous = null; }
        }
        const publicClient = new ApiClient(apiUrl, { allowInsecureDevelopment: isolated, fetch: appFetch });
        const response = await publicClient.authToken({ email: login.email.trim(), password: login.password, device_name: login.deviceName.trim() });
        const client = new ApiClient(apiUrl, { token: response.token, allowInsecureDevelopment: isolated, fetch: appFetch });
        let current: DeviceSession | null = null;
        let hash: string | null = null;
        let vaultAttempted = false;
        let previousBootstrap: Bootstrap | null | undefined;
        try {
          const bootstrap = await client.bootstrap();
          current = { apiUrl, owner: ownerFor(apiUrl, bootstrap.tenant.id, bootstrap.user.id), token: response.token, expiresAt: response.expires_at };
          hash = await credentialHash(response.token);
          await accountStore.update(current.owner, state => {
            previousBootstrap = state.bootstrap;
            return { ...state, initializingCredentialHashes: [...new Set([...initializingHashes(state), hash!])] };
          });
          vaultAttempted = true;
          await sessionVault.write(current);
          if (!sameCredential(await sessionVault.read(), current)) { throw new Error('The new device credential could not be verified. Sign in again.'); }
          const initialized = await accountStore.update(current.owner, state => {
            if (!initializingHashes(state).includes(hash!)) { throw new Error('The sign-in setup changed. Sign in again; saved sales are retained.'); }
            return { ...state, bootstrap };
          });
          acceptSession(current); acceptAccount(initialized); established = true;
          setCart([]); setReceiptId(null); setReview(false); reviewRequest.current = null; setDevices(null); setLogin(value => ({ ...value, password: '' }));
          setLocation(bootstrap.locations[0]?.id || null); setPhase('workspace'); navigate('inventory');
          await accountStore.update(current.owner, state => ({ ...state, initializingCredentialHashes: initializingHashes(state).filter(value => value !== hash) }));
          await refresh(current); setNotice('Your company catalog is saved on this device. Saved cash sales synchronize when connected.');
        } catch (failure) {
          if (!established) {
            if (current && hash && previousBootstrap !== undefined) {
              try { await accountStore.update(current.owner, state => initializingHashes(state).includes(hash!) ? { ...state, bootstrap: state.bootstrap === null ? null : previousBootstrap! } : state); } catch { /* The persisted fingerprint still prevents this credential from restoring a session. */ }
            }
            let revoked = false;
            try { await client.revoke(); revoked = true; }
            catch (cleanupFailure) { revoked = cleanupFailure instanceof ApiError && cleanupFailure.status === 401; }
            let vaultSafe = !vaultAttempted;
            if (vaultAttempted) {
              let held: DeviceSession | null | undefined;
              try { held = await sessionVault.read(); } catch { held = undefined; }
              if (held !== undefined && held?.token !== response.token) { vaultSafe = true; }
              else {
                try {
                  if (previous) { await sessionVault.write(previous); } else { await sessionVault.remove(); }
                  vaultSafe = (await sessionVault.read())?.token !== response.token;
                } catch { vaultSafe = false; }
              }
            }
            if (revoked && vaultSafe && current && hash) {
              try { await accountStore.update(current.owner, state => ({ ...state, initializingCredentialHashes: initializingHashes(state).filter(value => value !== hash) })); } catch { /* An unresolved initialization remains fail-closed on restart. */ }
            }
            if (!revoked || !vaultSafe) { setNotice('Sign-in setup failed. Its new device credential could not be fully cleared; sign in again when connected and review device access. Your original saved sales are retained.'); }
          }
          throw failure;
        }
      }, () => established ? sessionRef.current : null);
    },
    onTabChange: navigate,
    onSearch: setSearch,
    onScan: () => setScanOpen(true),
    onLocationChange: id => { if (cart.length) { setError('Complete or clear this cart before switching locations.'); return; } setLocation(id); setSearch(''); },
    onPriceTierChange: value => { if (cart.length) { setError('Complete or clear this cart before switching prices.'); return; } setTier(value); },
    onAddProduct: id => {
      const product = products.find(row => row.id === id);
      if (!product || !account || !locationId || !usable || checkoutReason || price(product) === null) { setError(checkoutReason || 'This price tier is unavailable.'); return; }
      const quantity = (cart.find(item => item.product_id === id)?.quantity || 0) + 1;
      if (quantity > available(product, locationId, account)) { setError('The saved stock cannot cover another unit.'); return; }
      setCart(previous => previous.some(item => item.product_id === id) ? previous.map(item => item.product_id === id ? { ...item, quantity } : item) : [...previous, { product_id: id, quantity }]); setError(null); navigate('pos');
    },
    onQuantityChange: (id, quantity) => {
      const product = products.find(row => row.id === id);
      if (!product || !account || !locationId || !Number.isInteger(quantity) || quantity < 1 || quantity > available(product, locationId, account)) { setError('Review the quantity and saved stock.'); return; }
      setCart(previous => previous.map(item => item.product_id === id ? { ...item, quantity } : item));
    },
    onRemoveProduct: id => setCart(previous => previous.filter(item => item.product_id !== id)),
    onClearCart: () => { setCart([]); setCashReceived(''); setDiscount('0'); setReview(false); reviewRequest.current = null; },
    onDiscountChange: setDiscount, onCashReceivedChange: setCashReceived,
    onReview: () => {
      try {
        if (checkoutReason || !totals || !snapshot || !locationId || cents(toMoney(cashReceived)) < totals.totalCents) { throw new Error(checkoutReason || 'Enter enough cash received and review the cart.'); }
        reviewRequest.current = { locationId, items: cart.map(item => ({ ...item })), tier, discountPercent: Number(discount), cashAmount: toMoney(cashReceived), snapshotPermit: snapshot.permit };
        setReview(true); setError(null);
      }
      catch (failure) { setError(failure instanceof Error ? failure.message : 'Review this sale.'); }
    },
    onCancelReview: () => { reviewRequest.current = null; setReview(false); },
    onConfirmSale: () => void run(async () => {
      const current = sessionRef.current;
      if (!current || !snapshot || !locationId || !reviewOpen || !cart.length || checkoutReason) { throw new Error(checkoutReason || 'Review the cash sale before recording it.'); }
      if (!reviewRequest.current) { throw new Error('Review the original cart and cash amount again.'); }
      const entry = await engine.enqueueOffline(current.owner, reviewRequest.current);
      reviewRequest.current = null;
      const knownAccount = accountRef.current;
      if (sessionRef.current?.owner === current.owner && knownAccount?.owner === current.owner) {
        acceptAccount({ ...knownAccount, queue: knownAccount.queue.some(saved => saved.id === entry.id) ? knownAccount.queue : [...knownAccount.queue, entry] });
      }
      setCart([]); setCashReceived(''); setDiscount('0'); setReview(false); setReceiptId(entry.id); navigate('sales');
      setNotice('Cash sale saved on this device. Keep its provisional receipt until the server confirms it.');
      try { await reload(current.owner); if (online && current.token) { await refresh(current); } }
      catch (failure) {
        await handleFailure(failure);
        setNotice('The cash sale is saved on this device. Reconnect to confirm its original request; do not record it again.');
      }
    }),
    onSync: () => void run(async () => { const current = sessionRef.current; if (!current) { throw new Error('Sign in as the original company user.'); } const value = await accountStore.read(current.owner); if (value?.pendingAction) { await performAction(current); } await refresh(current); }),
    onRefreshCatalog: () => void run(async () => { if (cart.length) { throw new Error('Complete or clear the unsaved cart before refreshing. Saved sales are retained.'); } const current = sessionRef.current; if (!current) { throw new Error('Sign in first.'); } await refresh(current, false); setNotice('Saved stock, tax, prices, and cash-session references refreshed.'); }),
    onViewReceipt: setReceiptId, onCloseReceipt: () => setReceiptId(null), onPrintReceipt: () => undefined,
    onRetrySale: () => { actions.onSync(); },
    onLoadDevices: () => void run(async () => {
      const current = sessionRef.current;
      if (!current?.token) { throw new Error('Sign in again to review your devices.'); }
      const response = await clientFor(current).devices();
      if (sessionRef.current?.owner === current.owner) {
        setDevices(response.data.map(device => ({ id: device.id, name: device.name, current: device.current, lastUsedAt: device.last_used_at })));
        setOnline(true);
      }
    }),
    onRevokeDevice: id => void run(async () => {
      const current = sessionRef.current;
      if (!current?.token || !devices?.some(device => device.id === id && !device.current)) { throw new Error('Review your signed-in devices before signing out another device.'); }
      await clientFor(current).revokeDevice(id);
      setDevices(previous => previous?.filter(device => device.id !== id) || null);
      setNotice('That device’s access was revoked. Saved sales remain recoverable when its original user signs in again.');
    }),
    onSignOut: () => void run(async () => {
      const current = sessionRef.current;
      let revocationPending = false;
      if (current) {
        if (current.token) {
          try { await clientFor(current).revoke(); }
          catch (failure) { revocationPending = !(failure instanceof ApiError && failure.status === 401); }
        }
        await engine.signOut(current.owner);
      }
      setNotice(revocationPending ? 'This device is signed out locally and saved requests are retained. Server token revocation could not be confirmed; revoke this device under mobile device access when connected.' : 'Signed out. Unconfirmed requests stay on this device for their original company user.');
      try { await sessionVault.remove(); }
      finally { acceptSession(null); acceptAccount(null); setDevices(null); setCart([]); setReceiptId(null); setReview(false); reviewRequest.current = null; setPhase('login'); }
    }),
    onRequireLogin: () => { setPhase('login'); setReceiptId(null); setReview(false); },
    onShiftDialog: value => { if (value && accountRef.current?.pendingAction) { setError('Open Settings and confirm the original saved update first.'); return; } setShiftDialog(value); setShiftAmount(''); setShiftNote(''); },
    onShiftAmountChange: setShiftAmount, onShiftNoteChange: setShiftNote,
    onSubmitShift: () => void run(async () => {
      if (!locationId) { throw new Error('Choose a stock location.'); }
      if (shiftDialog === 'close' && cashSession) { const reason = await engine.cashShiftBlock(session!.owner, cashSession.id); if (reason) { throw new Error(reason); } await saveAction('cash-close', `/cash-sessions/${cashSession.id}/close`, { counted_cash: toMoney(shiftAmount), reason: shiftNote.trim() }); }
      else if (shiftDialog === 'open') { await saveAction('cash-open', '/cash-sessions/open', { location_id: locationId, opening_float: toMoney(shiftAmount) }); }
    }),
    onAdjustProduct: id => { if (accountRef.current?.pendingAction) { setError('Open Settings and confirm the original saved update first.'); return; } const product = products.find(row => row.id === id); if (product) { setStockAdjustment({ productId: id, productName: product.name, quantity: '', reason: '' }); } },
    onAdjustmentField: (field, value) => setStockAdjustment(previous => previous ? { ...previous, [field]: value } : null),
    onCloseAdjustment: () => setStockAdjustment(null),
    onSubmitAdjustment: () => void run(async () => { if (!stockAdjustment || !locationId || !/^-?\d+$/.test(stockAdjustment.quantity) || Number(stockAdjustment.quantity) === 0 || !stockAdjustment.reason.trim()) { throw new Error('Enter a nonzero whole quantity and a reason.'); } await saveAction('stock-adjust', '/stock/adjust', { product_id: stockAdjustment.productId, location_id: locationId, quantity: Number(stockAdjustment.quantity), reason: stockAdjustment.reason.trim() }); }),
    onExportQueue: () => undefined,
  };

  const closeShiftReason = pending.length ? 'Sync all saved cash sales before closing their original shift.' : account?.pendingAction ? 'Confirm the original stock or cash update first.' : loginRequired || !online ? 'Connect and sign in before closing your cash session.' : null;
  const mapped = products.map(product => ({ id: product.id, name: product.name, variant: product.variant || '', sku: product.sku, barcode: product.barcode, stock: account && locationId ? available(product, locationId, account) : 0, priceCents: price(product) === null ? -1 : cents(price(product)!), lowStock: account && locationId ? available(product, locationId, account) <= product.reorder_level : false }));
  const normalized = search.trim().toLowerCase();
  const selected = receiptId ? account?.queue.find(entry => entry.id === receiptId) : null;
  const sorted = [...(account?.queue || [])].sort((a, b) => (a.status === 'confirmed' ? 1 : 0) - (b.status === 'confirmed' ? 1 : 0) || b.createdAt.localeCompare(a.createdAt));
  const model: AppViewModel = {
    phase, tab, busy, error, notice, isolated, apiOrigin: apiUrl, login,
    account: account?.bootstrap ? { company: account.bootstrap.tenant.name, ...account.bootstrap.user } : null,
    online, syncing, loginRequired, pendingCount: pending.length,
    catalog: { savedAt: snapshot?.captured_at || null, expiresAt: snapshot?.expires_at || null, usable, productCount: products.length },
    locations: snapshot?.locations || account?.bootstrap?.locations || [], locationId, priceTier: tier, search,
    products: mapped.filter(product => !normalized || `${product.name} ${product.variant} ${product.sku} ${product.barcode || ''}`.toLowerCase().includes(normalized)),
    cart: cart.flatMap(item => { const product = mapped.find(product => product.id === item.product_id); return product ? [{ ...product, quantity: item.quantity, totalCents: product.priceCents * item.quantity }] : []; }),
    discount, cashReceived, totals, checkoutAllowed: !checkoutReason && cart.length > 0 && !busy, checkoutReason, reviewOpen,
    savedSales: sorted.map(entry => ({ id: entry.id, number: entry.receipt?.number || entry.provisionalNumber, status: entry.status, totalCents: entry.quote.total_cents, itemCount: entry.payload.items.reduce((sum, item) => sum + item.quantity, 0), recordedAt: entry.createdAt, syncedAt: entry.receipt?.offline?.synced_at || entry.receipt?.created_at || null, location: entry.location, message: entry.error || null })),
    receipt: selected ? showReceipt(selected, account) : null,
    cashSession: cashSession ? { id: cashSession.id, location: modelLocation(snapshot, locationId), openingCents: cents(cashSession.opening_float), expectedCents: cents(cashSession.expected_cash) } : null,
    cashSessionKnown,
    shiftDialog, shiftAmount, shiftNote, closeShiftAllowed: !closeShiftReason && !!cashSession, closeShiftReason,
    canAdjustStock: !!account?.bootstrap?.abilities.includes('inventory:write') && online && !loginRequired && !account?.pendingAction,
    stockAdjustment, devices,
    pendingUpdate: account?.pendingAction ? `${account.pendingAction.kind === 'stock-adjust' ? 'Stock adjustment' : account.pendingAction.kind === 'cash-open' ? 'Cash session opening' : 'Cash session closing'} saved ${account.pendingAction.createdAt}. ${account.pendingAction.error || 'Waiting for confirmation.'} Keep this original update until your administrator confirms its outcome.` : null,
  };
  return { model, actions, scanOpen, setScanOpen, scan: (barcode: string) => { setSearch(barcode); setScanOpen(false); navigate('inventory'); }, session, account, run, setNotice };
}

function modelLocation(snapshot: AccountState['snapshot'], id: number | null): string {
  return snapshot?.locations.find(location => location.id === id)?.name || 'Location';
}
