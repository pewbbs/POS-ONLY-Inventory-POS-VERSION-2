import React from 'react';
import {
  ActivityIndicator,
  FlatList,
  Image,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
  type TextInputProps,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import type { AppActions, AppTab, AppViewModel, AppViewProps, ProductView, ReceiptView, TotalsView } from './types';

const colors = {
  paper: '#f4f2ee',
  white: '#ffffff',
  ink: '#191918',
  muted: '#68675f',
  line: '#e6e3dd',
  rust: '#982e27',
  rustLight: '#f6e9e5',
  green: '#416748',
  greenLight: '#ecf2eb',
  amber: '#775719',
  amberLight: '#f6efdf',
};

const tabs: { key: AppTab; label: string }[] = [
  { key: 'inventory', label: 'Inventory' },
  { key: 'pos', label: 'POS' },
  { key: 'sales', label: 'Saved sales' },
  { key: 'settings', label: 'Settings' },
];

export function formatMoney(cents: number): string {
  return `₱${(cents / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function dateLabel(value: string | null): string {
  if (!value) {
    return 'Not saved yet';
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }
  return date.toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function additionalVariant(name: string, variant: string): string {
  return variant && !name.toLowerCase().includes(variant.toLowerCase()) ? variant : '';
}

function Button({
  label,
  onPress,
  disabled = false,
  primary = false,
  testID,
  hint,
}: {
  label: string;
  onPress: () => void;
  disabled?: boolean;
  primary?: boolean;
  testID?: string;
  hint?: string;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityHint={hint}
      accessibilityState={{ disabled }}
      testID={testID}
      disabled={disabled}
      onPress={onPress}
      style={({ pressed }) => [styles.button, primary && styles.primaryButton, disabled && styles.disabled, pressed && !disabled && styles.pressed]}
    >
      <Text style={[styles.buttonLabel, primary && styles.primaryButtonLabel]}>{label}</Text>
    </Pressable>
  );
}

function Field({ label, hint, ...props }: TextInputProps & { label: string; hint?: string }) {
  return (
    <View style={styles.field}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <TextInput
        accessibilityLabel={label}
        accessibilityHint={hint}
        placeholderTextColor={colors.muted}
        style={[styles.input, props.multiline && styles.multilineInput]}
        {...props}
      />
      {hint ? <Text style={styles.small}>{hint}</Text> : null}
    </View>
  );
}

function Heading({ title, subtitle }: { title: string; subtitle: string }) {
  return (
    <View style={styles.heading}>
      <Text accessibilityRole="header" style={styles.title}>{title}</Text>
      <Text style={styles.bodyMuted}>{subtitle}</Text>
    </View>
  );
}

function Empty({ title, children }: { title: string; children: string }) {
  return (
    <View style={styles.empty}>
      <Text style={styles.sectionTitle}>{title}</Text>
      <Text style={styles.bodyMuted}>{children}</Text>
    </View>
  );
}

function SaleStatus({ status }: { status: ReceiptView['status'] }) {
  const confirmed = status === 'confirmed';
  const label = { pending: 'Awaiting sync', syncing: 'Syncing', conflict: 'Needs review', confirmed: 'Confirmed' }[status];
  return (
    <View style={[styles.status, confirmed ? styles.confirmedStatus : styles.pendingStatus]}>
      <Text style={[styles.statusText, confirmed ? styles.confirmedText : styles.pendingText]}>{label}</Text>
    </View>
  );
}

function Alerts({ model }: { model: AppViewModel }) {
  return (
    <>
      {model.error ? <Text accessibilityRole="alert" accessibilityLiveRegion="assertive" testID="app-error" style={styles.error}>{model.error}</Text> : null}
      {model.notice ? <Text accessibilityLiveRegion="polite" testID="app-notice" style={styles.notice}>{model.notice}</Text> : null}
    </>
  );
}

function ConnectionBanner({ model, actions }: AppViewProps) {
  const show = !model.online || model.loginRequired || model.pendingCount > 0 || model.syncing || !model.catalog.usable;
  if (!show) {
    return null;
  }
  return (
    <View style={[styles.connectionBanner, model.loginRequired && styles.connectionError]} testID="connection-banner">
      <View>
        <Text style={styles.bannerTitle}>
          {model.loginRequired ? 'Sign in again to sync' : model.syncing ? 'Syncing saved cash sales…' : !model.online ? 'Working offline' : model.pendingCount > 0 ? `${model.pendingCount} sale${model.pendingCount === 1 ? '' : 's'} waiting for sync` : 'Save a catalog to begin'}
        </Text>
        <Text style={styles.small}>
          {model.loginRequired
            ? 'Your queued sales remain on this device. Sign in to the same company account to recover them.'
            : model.syncing
              ? 'Keep the app open until the saved receipts are confirmed.'
              : !model.online
                ? model.catalog.usable ? 'Cash sales are saved on this device and sync when your connection returns.' : 'Your saved catalog has expired. Reconnect and refresh it before making another sale.'
                : model.pendingCount > 0
                  ? 'Reconnection sync runs while the app is open. You can also sync now.'
                  : 'A saved catalog is needed for cash checkout and offline inventory.'}
        </Text>
      </View>
      {model.loginRequired ? <Button label="Sign in" testID="recover-login" onPress={actions.onRequireLogin} /> : null}
      {!model.loginRequired && (model.pendingCount > 0 || !model.online) ? <Button label={model.syncing ? 'Syncing…' : !model.online ? 'Reconnect & sync' : 'Sync now'} testID="banner-sync" disabled={model.busy || model.syncing} onPress={actions.onSync} /> : null}
    </View>
  );
}

function CatalogControls({ model, actions, compact = false }: AppViewProps & { compact?: boolean }) {
  return (
    <View style={styles.catalogControls}>
      {!compact ? (
        <>
          <Text style={styles.fieldLabel}>Stock location</Text>
          <View style={styles.choiceRow}>
            {model.locations.map((location) => (
              <Pressable
                key={location.id}
                accessibilityRole="radio"
                accessibilityLabel={location.name}
                accessibilityState={{ checked: model.locationId === location.id, disabled: model.busy }}
                aria-checked={model.locationId === location.id}
                disabled={model.busy}
                testID={`location-${location.id}`}
                onPress={() => actions.onLocationChange(location.id)}
                style={[styles.choice, model.locationId === location.id && styles.selectedChoice]}
              >
                <Text style={[styles.choiceText, model.locationId === location.id && styles.selectedChoiceText]}>{location.name}</Text>
              </Pressable>
            ))}
          </View>
          <Text style={styles.fieldLabel}>Pricing</Text>
          <View style={styles.choiceRow}>
            {(['retail', 'wholesale'] as const).map((tier) => (
              <Pressable
                key={tier}
                accessibilityRole="radio"
                accessibilityLabel={`${tier === 'retail' ? 'Retail' : 'Wholesale'} prices`}
                accessibilityState={{ checked: model.priceTier === tier, disabled: model.busy }}
                aria-checked={model.priceTier === tier}
                disabled={model.busy}
                testID={`tier-${tier}`}
                onPress={() => actions.onPriceTierChange(tier)}
                style={[styles.choice, model.priceTier === tier && styles.selectedChoice]}
              >
                <Text style={[styles.choiceText, model.priceTier === tier && styles.selectedChoiceText]}>{tier === 'retail' ? 'Retail' : 'Wholesale'}</Text>
              </Pressable>
            ))}
          </View>
        </>
      ) : null}
      <Field
        label="Find a product"
        testID="product-search"
        placeholder="Product, variant, SKU or barcode"
        value={model.search}
        onChangeText={actions.onSearch}
        autoCapitalize="none"
        autoCorrect={false}
        returnKeyType="search"
      />
      <View style={styles.row}>
        <Button label="Scan barcode" testID="scan-barcode" disabled={model.busy} onPress={actions.onScan} />
        {!compact ? <Button label="Refresh catalog" testID="refresh-catalog" disabled={model.busy || model.loginRequired} onPress={actions.onRefreshCatalog} /> : null}
      </View>
      {!compact ? <Text style={styles.small}>Saved {dateLabel(model.catalog.savedAt)} · {model.catalog.productCount} products{model.catalog.expiresAt ? ` · Valid until ${dateLabel(model.catalog.expiresAt)}` : ''}</Text> : null}
    </View>
  );
}

function ProductRow({ product, model, actions }: { product: ProductView; model: AppViewModel; actions: AppActions }) {
  return (
    <View style={styles.productRow} testID={`product-${product.id}`}>
      <View style={styles.flex}>
        <Text style={styles.productName}>{product.name}</Text>
        {additionalVariant(product.name, product.variant) ? <Text style={styles.bodyMuted}>{product.variant}</Text> : null}
        <Text style={styles.small}>{product.sku}{product.barcode ? ` · ${product.barcode}` : ''}</Text>
        <Text style={[styles.stock, product.lowStock && styles.lowStock]}>{product.stock} available{product.lowStock && product.stock > 0 ? ' · Low stock' : ''}</Text>
      </View>
      <View style={styles.productAction}>
        <Text style={styles.productPrice}>{product.priceCents < 0 ? 'Price unavailable' : formatMoney(product.priceCents)}</Text>
        <Button
          label="Add"
          hint={`Add ${product.name}${additionalVariant(product.name, product.variant) ? `, ${product.variant}` : ''} to the sale`}
          testID={`add-product-${product.id}`}
          disabled={model.busy || !model.catalog.usable || model.loginRequired || product.stock <= 0 || product.priceCents < 0}
          onPress={() => actions.onAddProduct(product.id)}
        />
        {model.canAdjustStock && model.tab === 'inventory' ? <Button label="Adjust stock" testID={`adjust-product-${product.id}`} disabled={!model.online || model.busy || model.loginRequired} onPress={() => actions.onAdjustProduct(product.id)} /> : null}
      </View>
    </View>
  );
}

function InventoryScreen(props: AppViewProps) {
  const { model } = props;
  return (
    <FlatList
      testID="inventory-screen"
      contentContainerStyle={styles.listContent}
      keyboardShouldPersistTaps="handled"
      data={model.products}
      keyExtractor={(product) => String(product.id)}
      ListHeaderComponent={<><Heading title="Inventory" subtitle="Find the right product and check stock at each location." /><CatalogControls {...props} /></>}
      renderItem={({ item }) => <ProductRow product={item} {...props} />}
      ListEmptyComponent={<Empty title={model.catalog.savedAt ? 'No products found' : 'Save your inventory'}>{model.catalog.savedAt ? 'Try a product name, variant, SKU or barcode.' : 'Connect to the internet and refresh the catalog. Your saved inventory will then be available offline.'}</Empty>}
      ListFooterComponent={<Text style={styles.footerNote}>Available stock includes cash sales waiting to sync on this device. Stock held by other stores or devices is checked again during synchronization.</Text>}
    />
  );
}

function Totals({ totals, showChange = false }: { totals: TotalsView; showChange?: boolean }) {
  return (
    <View style={styles.totals}>
      <View style={styles.receiptRow}><Text style={styles.bodyMuted}>Subtotal</Text><Text style={styles.body}>{formatMoney(totals.subtotalCents)}</Text></View>
      {totals.discountCents > 0 ? <View style={styles.receiptRow}><Text style={styles.bodyMuted}>Discount</Text><Text style={styles.body}>−{formatMoney(totals.discountCents)}</Text></View> : null}
      <View style={styles.receiptRow}><Text style={styles.bodyMuted}>Tax</Text><Text style={styles.body}>{formatMoney(totals.taxCents)}</Text></View>
      <View style={[styles.receiptRow, styles.totalRow]}><Text style={styles.totalLabel}>Total</Text><Text testID="sale-total" style={styles.totalValue}>{formatMoney(totals.totalCents)}</Text></View>
      {showChange ? <View style={styles.receiptRow}><Text style={styles.bodyMuted}>Change</Text><Text testID="cash-change" style={styles.sectionTitle}>{formatMoney(totals.changeCents)}</Text></View> : null}
    </View>
  );
}

function PosScreen(props: AppViewProps) {
  const { model, actions } = props;
  return (
    <ScrollView testID="pos-screen" contentContainerStyle={styles.screenContent} keyboardShouldPersistTaps="handled">
      <Heading title="Point of sale" subtitle="Build a sale, review the total, then record the cash received." />
      <View style={styles.section}>
        <View style={styles.sectionHeading}><Text style={styles.sectionTitle}>Current sale</Text>{model.cart.length > 0 ? <Button label="Clear cart" testID="clear-cart" disabled={model.busy} onPress={actions.onClearCart} /> : null}</View>
        <Text style={styles.small}>{model.locations.find((location) => location.id === model.locationId)?.name ?? 'Choose a stock location'} · {model.priceTier === 'retail' ? 'Retail' : 'Wholesale'} · Walk-in customer</Text>
        {model.cart.length === 0 ? <Empty title="Your cart is empty">Scan a barcode or choose products from inventory to begin.</Empty> : model.cart.map((item) => (
          <View style={styles.cartRow} key={item.id} testID={`cart-product-${item.id}`}>
            <View style={styles.flex}>
              <Text style={styles.productName}>{item.name}</Text>
              {additionalVariant(item.name, item.variant) ? <Text style={styles.small}>{item.variant}</Text> : null}
              <Text style={styles.small}>{formatMoney(item.priceCents)} each · {formatMoney(item.totalCents)}</Text>
            </View>
            <View style={styles.quantityAndRemove}>
              <View style={styles.quantityControl}>
                <Pressable accessibilityRole="button" accessibilityLabel={`Decrease quantity of ${item.name}`} testID={`decrease-${item.id}`} disabled={model.busy} onPress={() => actions.onQuantityChange(item.id, item.quantity - 1)} style={styles.quantityButton}><Text style={styles.quantityMark}>−</Text></Pressable>
                <Text accessibilityLabel={`Quantity ${item.quantity}`} testID={`quantity-${item.id}`} style={styles.quantityValue}>{item.quantity}</Text>
                <Pressable accessibilityRole="button" accessibilityLabel={`Increase quantity of ${item.name}`} testID={`increase-${item.id}`} disabled={model.busy || item.quantity >= item.stock || !model.catalog.usable} onPress={() => actions.onQuantityChange(item.id, item.quantity + 1)} style={[styles.quantityButton, (item.quantity >= item.stock || !model.catalog.usable) && styles.disabled]}><Text style={styles.quantityMark}>+</Text></Pressable>
              </View>
              <Pressable accessibilityRole="button" accessibilityLabel={`Remove ${item.name} from cart`} testID={`remove-${item.id}`} disabled={model.busy} onPress={() => actions.onRemoveProduct(item.id)} style={styles.textButton}><Text style={styles.linkText}>Remove</Text></Pressable>
            </View>
          </View>
        ))}
        {model.cart.length > 0 ? (
          <>
            <Field label="Discount (%)" value={model.discount} testID="sale-discount" onChangeText={actions.onDiscountChange} keyboardType="decimal-pad" editable={!model.busy} hint="Enter a percentage from 0 to 100." />
            {model.totals ? <Totals totals={model.totals} /> : null}
            <Field label="Cash received (₱)" testID="cash-received" value={model.cashReceived} onChangeText={actions.onCashReceivedChange} keyboardType="decimal-pad" editable={!model.busy} hint="Cash only. No card or wallet payment is taken by this app." />
            {model.totals && model.totals.changeCents >= 0 ? <View style={styles.receiptRow}><Text style={styles.bodyMuted}>Change</Text><Text style={styles.sectionTitle}>{formatMoney(model.totals.changeCents)}</Text></View> : null}
            {model.checkoutReason ? <Text style={styles.reason}>{model.checkoutReason}</Text> : null}
            <Button label="Review cash sale" primary testID="review-sale" disabled={model.busy || !model.checkoutAllowed} onPress={actions.onReview} />
            <Text style={styles.small}>The receipt stays provisional until the server confirms the sale. Keep this device’s saved data until all sales are confirmed.</Text>
          </>
        ) : null}
      </View>
      <CatalogControls {...props} compact />
      {model.search ? model.products.slice(0, 12).map((product) => <ProductRow key={product.id} product={product} {...props} />) : null}
      {model.search && model.products.length === 0 ? <Empty title="No matching products">Check the barcode or search by product name.</Empty> : null}
      <Button label={model.search && model.products.length > 12 ? 'See all matching products' : 'Browse inventory'} testID="browse-inventory" onPress={() => actions.onTabChange('inventory')} />
    </ScrollView>
  );
}

function SalesScreen({ model, actions }: AppViewProps) {
  return (
    <FlatList
      testID="saved-sales-screen"
      data={[...model.savedSales].sort((first, second) => {
        const priority = (status: ReceiptView['status']) => status === 'confirmed' ? 1 : 0;
        return priority(first.status) - priority(second.status) || second.recordedAt.localeCompare(first.recordedAt);
      })}
      keyExtractor={(sale) => sale.id}
      contentContainerStyle={styles.listContent}
      ListHeaderComponent={
        <>
          <Heading title="Saved sales" subtitle="Queued and review-needed sales stay here until they are confirmed." />
          <View style={styles.section}>
            <Text style={styles.sectionTitle}>{model.pendingCount} awaiting confirmation</Text>
            <Text style={styles.bodyMuted}>Reconnection sync runs while this app is open. Each sale keeps its original amount and reference during retries.</Text>
            <Button label={model.syncing ? 'Syncing…' : !model.online ? 'Reconnect & sync saved sales' : 'Sync saved sales'} primary testID="sync-sales" disabled={model.busy || model.syncing || model.loginRequired || model.pendingCount === 0} onPress={actions.onSync} />
          </View>
        </>
      }
      renderItem={({ item: sale }) => (
        <View style={styles.saleRow} testID={`saved-sale-${sale.id}`}>
          <View style={styles.sectionHeading}><Text style={[styles.sectionTitle, styles.flex]}>{sale.number}</Text><SaleStatus status={sale.status} /></View>
          <View style={styles.receiptRow}><Text style={styles.bodyMuted}>{sale.itemCount} item{sale.itemCount === 1 ? '' : 's'} · {sale.location}</Text><Text style={styles.productPrice}>{formatMoney(sale.totalCents)}</Text></View>
          <Text style={styles.small}>Recorded {dateLabel(sale.recordedAt)}{sale.syncedAt ? `\nConfirmed ${dateLabel(sale.syncedAt)}` : ''}</Text>
          {sale.message ? <Text style={styles.reason}>{sale.message}</Text> : null}
          <View style={styles.row}>
            <Button label="View receipt" testID={`receipt-${sale.id}`} onPress={() => actions.onViewReceipt(sale.id)} />
            {sale.status === 'conflict' || sale.status === 'pending' ? <Button label="Retry sync" testID={`retry-${sale.id}`} disabled={model.busy || model.syncing || model.loginRequired} onPress={() => actions.onRetrySale(sale.id)} /> : null}
          </View>
        </View>
      )}
      ListEmptyComponent={<Empty title="No saved sales yet">Cash sales recorded on this device will appear here, with their confirmation status.</Empty>}
      ListFooterComponent={<Text style={styles.footerNote}>If a sale needs review, ask your administrator to check stock, pricing and the original cash session. Do not enter it again as a new sale.</Text>}
    />
  );
}

function SettingsScreen({ model, actions }: AppViewProps) {
  return (
    <ScrollView testID="settings-screen" contentContainerStyle={styles.screenContent} keyboardShouldPersistTaps="handled">
      <Heading title="Settings & help" subtitle="Your company account, saved inventory and cash session." />
      <View style={styles.section}>
        <Text style={styles.sectionTitle}>{model.account?.company}</Text>
        <Text style={styles.body}>{model.account?.name}</Text>
        <Text style={styles.bodyMuted}>{model.account?.email} · {model.account?.role}</Text>
        <Text style={styles.small}>Connected to {model.apiOrigin}</Text>
        <Button label="Sign out" testID="sign-out" disabled={model.busy || model.syncing} onPress={actions.onSignOut} />
        <Text style={styles.small}>Queued cash sales stay on this trusted device. They are visible again when you sign in to the same company account. Signing out removes access to its saved inventory.</Text>
        {model.pendingCount > 0 ? <Button label="Export queued sales backup" testID="export-queued-sales" disabled={model.busy || model.syncing} onPress={actions.onExportQueue} /> : null}
        {model.pendingCount > 0 ? <Text style={styles.small}>This backup contains company sales data. Keep it private. Exporting does not confirm or remove queued sales.</Text> : null}
      </View>
      <View style={styles.section}>
        <Text style={styles.sectionTitle}>Signed-in devices</Text>
        <Text style={styles.bodyMuted}>Review devices with access to this company account. Sign out any device you no longer use.</Text>
        <Button label="Review signed-in devices" testID="load-devices" disabled={model.busy || model.loginRequired} onPress={actions.onLoadDevices} />
        {model.devices?.length === 0 ? <Text style={styles.small}>No signed-in devices were found.</Text> : null}
        {model.devices?.map(device => <View key={device.id} style={styles.deviceRow} testID={`signed-in-device-${device.id}`}><Text style={styles.productName}>{device.name}{device.current ? ' · This device' : ''}</Text><Text style={styles.small}>Last used {dateLabel(device.lastUsedAt)}</Text>{!device.current ? <Button label="Sign out device" testID={`revoke-device-${device.id}`} disabled={!model.online || model.busy || model.loginRequired} onPress={() => actions.onRevokeDevice(device.id)} /> : null}</View>)}
        <Text style={styles.small}>Signing out this phone is available in your account settings above. Sync saved sales before signing out another phone.</Text>
      </View>
      <View style={styles.section}>
        <Text style={styles.sectionTitle}>Offline inventory</Text>
        <Text style={styles.bodyMuted}>Saved {dateLabel(model.catalog.savedAt)}</Text>
        <Text style={styles.bodyMuted}>{model.catalog.expiresAt ? `Valid until ${dateLabel(model.catalog.expiresAt)}` : 'No saved catalog'} · {model.catalog.productCount} products</Text>
        <Button label="Save fresh catalog" primary testID="save-catalog" disabled={model.busy || model.loginRequired} onPress={actions.onRefreshCatalog} />
        <Text style={styles.small}>Save a catalog while connected before a shift. Refresh it when prices or stock change. New cash sales stop when the saved catalog expires; earlier queued sales remain recoverable.</Text>
      </View>
      <View style={styles.section}>
        <Text style={styles.sectionTitle}>Cash session</Text>
        {model.cashSessionKnown && model.cashSession ? (
          <>
            <Text style={styles.bodyMuted}>Open at {model.cashSession.location}</Text>
            <View style={styles.receiptRow}><Text style={styles.bodyMuted}>Opening cash</Text><Text style={styles.body}>{formatMoney(model.cashSession.openingCents)}</Text></View>
            <View style={styles.receiptRow}><Text style={styles.bodyMuted}>Expected cash</Text><Text style={styles.body}>{formatMoney(model.cashSession.expectedCents)}</Text></View>
            <Text style={styles.small}>The server total may exclude cash sales still waiting to sync.</Text>
            <Button label="Close cash session" testID="close-cash-session" disabled={!model.online || model.busy || !model.closeShiftAllowed || model.loginRequired} onPress={() => actions.onShiftDialog('close')} />
            {model.closeShiftReason ? <Text style={styles.reason}>{model.closeShiftReason}</Text> : null}
          </>
        ) : (
          <>
            <Text style={styles.bodyMuted}>{model.cashSessionKnown ? 'No open cash session at the selected location.' : 'Reconnect to check your cash session.'}</Text>
            {!model.cashSessionKnown ? <Button label="Check cash session" testID="check-cash-session" disabled={model.busy || model.syncing || model.loginRequired} onPress={actions.onSync} /> : null}
            <Button label="Open cash session" testID="open-cash-session" disabled={!model.online || model.busy || model.loginRequired || !model.cashSessionKnown || model.locationId === null} onPress={() => actions.onShiftDialog('open')} />
          </>
        )}
        <Text style={styles.small}>Open and close sessions while connected. Confirm all saved sales before closing the session used to record them.</Text>
      </View>
      {model.pendingUpdate ? <View style={styles.section} testID="pending-update"><Text style={styles.sectionTitle}>Update awaiting confirmation</Text><Text style={styles.reason}>{model.pendingUpdate}</Text><Text style={styles.small}>The original request is retained. Retry it to verify its result before making another stock or cash-session update. If it needs review, ask your administrator to check the original reference and reason.</Text><Button label="Retry original update" testID="retry-original-update" disabled={model.busy || model.syncing || model.loginRequired} onPress={actions.onSync} /></View> : null}
      <View style={styles.section}>
        <Text accessibilityRole="header" style={styles.sectionTitle}>A simple shift guide</Text>
        <Text style={styles.guideText}><Text style={styles.guideStep}>1. Get ready. </Text>Sign in, choose your location and save a fresh catalog. Open a cash session with your starting cash.</Text>
        <Text style={styles.guideText}><Text style={styles.guideStep}>2. Make a sale. </Text>Scan or search for products, adjust quantities, enter cash received and review the amount before confirming. A provisional receipt means the sale is saved on this device.</Text>
        <Text style={styles.guideText}><Text style={styles.guideStep}>3. Reconnect. </Text>Keep the app open when the internet returns, or tap Sync saved sales. Check that every receipt says Confirmed before closing your cash session.</Text>
        <Text style={styles.guideText}><Text style={styles.guideStep}>Need to sign in again? </Text>Use the same company account. Your existing queued sales will be recovered without creating new copies.</Text>
        <Text style={styles.guideText}><Text style={styles.guideStep}>Needs review? </Text>Keep the original sale. Ask your administrator to check the server’s reason. Retrying keeps its original reference; clearing app storage or reinstalling can erase sales that have not synced.</Text>
      </View>
    </ScrollView>
  );
}

function LoginScreen({ model, actions }: AppViewProps) {
  return (
    <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={styles.loginContent}>
        <Text style={styles.loginBrand}>POSONLY<Text style={styles.brandDot}>.</Text></Text>
        <Heading title="Your work, in hand." subtitle="Sign in to your existing company account to check inventory and make cash sales." />
        {model.isolated ? <Text style={styles.isolationNotice}>Isolated test app · Fictitious data · No live payments</Text> : null}
        <Alerts model={model} />
        <View style={styles.loginForm}>
          <Field label="Email address" testID="login-email" value={model.login.email} onChangeText={(value) => actions.onLoginField('email', value)} keyboardType="email-address" autoCapitalize="none" autoCorrect={false} autoComplete="email" textContentType="emailAddress" editable={!model.busy} />
          <Field label="Password" testID="login-password" value={model.login.password} onChangeText={(value) => actions.onLoginField('password', value)} secureTextEntry autoCapitalize="none" autoCorrect={false} autoComplete="current-password" textContentType="password" editable={!model.busy} onSubmitEditing={actions.onLogin} />
          <Field label="Device name" testID="login-device-name" value={model.login.deviceName} onChangeText={(value) => actions.onLoginField('deviceName', value)} placeholder="Front counter phone" autoCorrect={false} maxLength={100} editable={!model.busy} hint="A name you can recognize when reviewing signed-in devices." />
          <Button label={model.busy ? 'Signing in…' : 'Sign in'} primary testID="login-submit" disabled={model.busy || !model.login.email.trim() || !model.login.password || !model.login.deviceName.trim()} onPress={actions.onLogin} />
        </View>
        {!model.online ? <Text style={styles.reason}>Connect to the internet to sign in. Previously saved cash sales remain on this device.</Text> : null}
        <Text style={styles.footerNote}>Use your company password. Google sign-in and password recovery are available on the POSONLY website.</Text>
        <Text style={styles.serverLabel}>Server: {model.apiOrigin}</Text>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

function Sheet({ visible, title, onClose, children, testID }: { visible: boolean; title: string; onClose: () => void; children: React.ReactNode; testID: string }) {
  return (
    <Modal visible={visible} onRequestClose={onClose} animationType="slide" presentationStyle="pageSheet">
      <SafeAreaView style={styles.safeArea}>
        <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
          <View style={styles.modalHeader}><Text accessibilityRole="header" style={[styles.sectionTitle, styles.flex]}>{title}</Text><Button label="Close" testID={`${testID}-close`} onPress={onClose} /></View>
          <ScrollView testID={testID} contentContainerStyle={styles.screenContent} keyboardShouldPersistTaps="handled">{children}</ScrollView>
        </KeyboardAvoidingView>
      </SafeAreaView>
    </Modal>
  );
}

function ReceiptContent({ receipt }: { receipt: ReceiptView }) {
  return (
    <View style={styles.receiptPaper}>
      {receipt.logoUrl ? <Image source={{ uri: receipt.logoUrl }} accessibilityLabel={`${receipt.company} logo`} style={styles.receiptLogo} resizeMode="contain" /> : null}
      <Text style={styles.receiptCompany}>{receipt.company}</Text>
      {receipt.address ? <Text style={styles.receiptDetail}>{receipt.address}</Text> : null}
      {receipt.contact ? <Text style={styles.receiptDetail}>{receipt.contact}</Text> : null}
      <View style={styles.receiptStatus}><SaleStatus status={receipt.status} /></View>
      <Text style={styles.receiptNumber}>{receipt.number}</Text>
      <Text style={styles.small}>{receipt.location} · {receipt.cashier}</Text>
      <Text style={styles.small}>Recorded {dateLabel(receipt.recordedAt)}{receipt.syncedAt ? `\nConfirmed ${dateLabel(receipt.syncedAt)}` : ''}</Text>
      {receipt.status !== 'confirmed' ? <Text style={styles.reason}>Provisional receipt. Saved on this device; awaiting server confirmation.</Text> : null}
      {receipt.message ? <Text style={styles.reason}>{receipt.message}</Text> : null}
      <View style={styles.receiptLines}>
        {receipt.lines.map((line, index) => (
          <View key={`${line.name}-${index}`} style={styles.receiptLine}>
            <Text style={styles.productName}>{line.name}</Text>
            {line.variant ? <Text style={styles.small}>{line.variant}</Text> : null}
            <View style={styles.receiptRow}><Text style={styles.bodyMuted}>{line.quantity} × {formatMoney(line.priceCents)}</Text><Text style={styles.body}>{formatMoney(line.totalCents)}</Text></View>
          </View>
        ))}
      </View>
      <Totals totals={receipt.totals} />
      <View style={styles.receiptRow}><Text style={styles.bodyMuted}>Cash received</Text><Text style={styles.body}>{formatMoney(receipt.receivedCents)}</Text></View>
      <View style={styles.receiptRow}><Text style={styles.bodyMuted}>Change</Text><Text style={styles.body}>{formatMoney(receipt.totals.changeCents)}</Text></View>
      {receipt.footer ? <Text style={styles.receiptFooter}>{receipt.footer}</Text> : null}
    </View>
  );
}

function Dialogs({ model, actions }: AppViewProps) {
  return (
    <>
      <Sheet visible={model.reviewOpen} title="Review cash sale" onClose={actions.onCancelReview} testID="sale-review">
        <Alerts model={model} />
        <Text style={styles.bodyMuted}>{model.locations.find((location) => location.id === model.locationId)?.name} · {model.priceTier === 'retail' ? 'Retail' : 'Wholesale'}</Text>
        <View style={styles.receiptLines}>{model.cart.map((item) => <View key={item.id} style={styles.receiptLine}><Text style={styles.productName}>{item.name}{additionalVariant(item.name, item.variant) ? ` · ${item.variant}` : ''}</Text><View style={styles.receiptRow}><Text style={styles.bodyMuted}>{item.quantity} × {formatMoney(item.priceCents)}</Text><Text style={styles.body}>{formatMoney(item.totalCents)}</Text></View></View>)}</View>
        {model.totals ? <Totals totals={model.totals} showChange /> : null}
        <Text style={styles.body}>Cash received: ₱{model.cashReceived || '0.00'}</Text>
        <Text style={styles.bodyMuted}>This records a cash sale. The original receipt and amount are saved first, then sent to your company when connected.</Text>
        {model.checkoutReason ? <Text style={styles.reason}>{model.checkoutReason}</Text> : null}
        <Button label={model.busy ? 'Saving sale…' : 'Confirm cash sale'} primary testID="confirm-cash-sale" disabled={model.busy || !model.checkoutAllowed} onPress={actions.onConfirmSale} />
        <Button label="Back to cart" disabled={model.busy} onPress={actions.onCancelReview} />
      </Sheet>
      <Sheet visible={model.receipt !== null} title="Receipt" onClose={actions.onCloseReceipt} testID="receipt-view">
        {model.receipt ? <ReceiptContent receipt={model.receipt} /> : null}
        <Button label="Print or save receipt" testID="print-receipt" disabled={model.busy} onPress={actions.onPrintReceipt} />
        <Text style={styles.small}>Printing does not change the sale’s confirmation status.</Text>
      </Sheet>
      <Sheet visible={model.shiftDialog !== null} title={model.shiftDialog === 'close' ? 'Close cash session' : 'Open cash session'} onClose={() => actions.onShiftDialog(null)} testID="cash-session-dialog">
        <Alerts model={model} />
        <Text style={styles.bodyMuted}>{model.shiftDialog === 'close' ? 'Count the drawer after all saved sales have been confirmed.' : 'Enter the cash in the drawer before starting your shift.'}</Text>
        {model.shiftDialog === 'close' && model.cashSession ? <View style={styles.receiptRow}><Text style={styles.bodyMuted}>Expected cash</Text><Text style={styles.sectionTitle}>{formatMoney(model.cashSession.expectedCents)}</Text></View> : null}
        <Field label={model.shiftDialog === 'close' ? 'Counted cash (₱)' : 'Opening cash (₱)'} testID="shift-amount" value={model.shiftAmount} onChangeText={actions.onShiftAmountChange} keyboardType="decimal-pad" editable={!model.busy} />
        <Field label="Note (optional)" testID="shift-note" value={model.shiftNote} onChangeText={actions.onShiftNoteChange} multiline maxLength={1000} editable={!model.busy} />
        {model.shiftDialog === 'close' && model.closeShiftReason ? <Text style={styles.reason}>{model.closeShiftReason}</Text> : null}
        <Button label={model.busy ? 'Saving…' : model.shiftDialog === 'close' ? 'Confirm closing count' : 'Start cash session'} primary testID="shift-submit" disabled={model.busy || !model.online || model.loginRequired || !model.cashSessionKnown || (model.shiftDialog === 'close' && !model.closeShiftAllowed)} onPress={actions.onSubmitShift} />
      </Sheet>
      <Sheet visible={model.stockAdjustment !== null} title="Adjust stock" onClose={actions.onCloseAdjustment} testID="stock-adjustment-dialog">
        <Alerts model={model} />
        <Text style={styles.sectionTitle}>{model.stockAdjustment?.productName}</Text>
        <Text style={styles.bodyMuted}>{model.locations.find((location) => location.id === model.locationId)?.name}</Text>
        <Field label="Quantity change" testID="adjustment-quantity" value={model.stockAdjustment?.quantity ?? ''} onChangeText={(value) => actions.onAdjustmentField('quantity', value)} autoCapitalize="none" autoCorrect={false} editable={!model.busy} placeholder="5 or -2" hint="Use a positive number to add stock, or a minus sign to remove stock." />
        <Field label="Reason" testID="adjustment-reason" value={model.stockAdjustment?.reason ?? ''} onChangeText={(value) => actions.onAdjustmentField('reason', value)} multiline maxLength={1000} editable={!model.busy} placeholder="Count correction, damage or another reason" />
        <Text style={styles.small}>Stock adjustments require a connection and administrator or manager access. Queued cash sales are checked when they sync.</Text>
        <Button label={model.busy ? 'Saving…' : 'Confirm stock adjustment'} primary testID="adjustment-submit" disabled={model.busy || !model.online || model.loginRequired || !model.canAdjustStock || !model.stockAdjustment?.quantity.trim() || !model.stockAdjustment?.reason.trim()} onPress={actions.onSubmitAdjustment} />
      </Sheet>
    </>
  );
}

export function AppView({ model, actions }: AppViewProps) {
  if (model.phase === 'loading') {
    return <SafeAreaView style={styles.safeArea}><View style={styles.loading}><Text style={styles.loginBrand}>POSONLY<Text style={styles.brandDot}>.</Text></Text><ActivityIndicator color={colors.rust} /><Text style={styles.bodyMuted}>Opening your saved workspace…</Text></View></SafeAreaView>;
  }
  if (model.phase === 'login') {
    return <SafeAreaView style={styles.safeArea}><LoginScreen model={model} actions={actions} /></SafeAreaView>;
  }
  return (
    <SafeAreaView style={styles.safeArea}>
      <View style={styles.appWidth}>
        <View style={styles.appHeader}>
          <View style={styles.flex}><Text style={styles.brand}>POSONLY<Text style={styles.brandDot}>.</Text></Text><Text style={styles.companyLabel} numberOfLines={1}>{model.account?.company}</Text></View>
          <View style={[styles.status, model.online ? styles.confirmedStatus : styles.pendingStatus]}><Text style={[styles.statusText, model.online ? styles.confirmedText : styles.pendingText]}>{model.online ? 'Connected' : 'Offline'}</Text></View>
        </View>
        {model.isolated ? <Text style={styles.isolationStrip}>Isolated test · No live payments</Text> : null}
        <ConnectionBanner model={model} actions={actions} />
        <View style={styles.alertContainer}><Alerts model={model} /></View>
        <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
          {model.tab === 'inventory' ? <InventoryScreen model={model} actions={actions} /> : model.tab === 'pos' ? <PosScreen model={model} actions={actions} /> : model.tab === 'sales' ? <SalesScreen model={model} actions={actions} /> : <SettingsScreen model={model} actions={actions} />}
        </KeyboardAvoidingView>
        <View style={styles.tabBar} accessibilityRole="tablist">
          {tabs.map((tab) => (
            <Pressable
              key={tab.key}
              accessibilityRole="tab"
              accessibilityLabel={tab.label}
              accessibilityState={{ selected: model.tab === tab.key }}
              aria-selected={model.tab === tab.key}
              testID={`tab-${tab.key}`}
              onPress={() => actions.onTabChange(tab.key)}
              style={[styles.tab, model.tab === tab.key && styles.activeTab]}
            >
              <Text style={[styles.tabLabel, model.tab === tab.key && styles.activeTabLabel]}>{tab.label}</Text>
              {tab.key === 'sales' && model.pendingCount > 0 ? <Text style={styles.tabCount}>{model.pendingCount} waiting</Text> : tab.key === 'pos' && model.cart.length > 0 ? <Text style={styles.tabCount}>{model.cart.reduce((count, item) => count + item.quantity, 0)} items</Text> : null}
            </Pressable>
          ))}
        </View>
      </View>
      <Dialogs model={model} actions={actions} />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: colors.paper },
  flex: { flex: 1, minWidth: 0 },
  appWidth: { flex: 1, width: '100%', maxWidth: 900, alignSelf: 'center' },
  appHeader: { flexDirection: 'row', alignItems: 'center', gap: 14, paddingHorizontal: 20, paddingVertical: 14, borderBottomWidth: 1, borderBottomColor: colors.line },
  brand: { color: colors.ink, fontSize: 23, fontWeight: '800', letterSpacing: -0.9 },
  brandDot: { color: colors.rust },
  companyLabel: { color: colors.muted, fontSize: 13, marginTop: 3 },
  loading: { flex: 1, justifyContent: 'center', alignItems: 'center', gap: 24 },
  loginContent: { flexGrow: 1, justifyContent: 'center', padding: 24, gap: 20, width: '100%', maxWidth: 540, alignSelf: 'center' },
  loginBrand: { fontSize: 34, fontWeight: '800', letterSpacing: -1.3, color: colors.ink },
  loginForm: { backgroundColor: colors.white, borderColor: colors.line, borderWidth: 1, borderRadius: 10, padding: 20, gap: 18 },
  serverLabel: { fontSize: 12, color: colors.muted, lineHeight: 18 },
  screenContent: { padding: 20, gap: 20, paddingBottom: 32 },
  listContent: { padding: 20, paddingBottom: 32 },
  heading: { gap: 8, marginBottom: 18 },
  title: { color: colors.ink, fontSize: 27, fontWeight: '700', letterSpacing: -0.7, lineHeight: 34 },
  body: { color: colors.ink, fontSize: 14, lineHeight: 22 },
  bodyMuted: { color: colors.muted, fontSize: 14, lineHeight: 22 },
  small: { color: colors.muted, fontSize: 12, lineHeight: 19 },
  sectionTitle: { color: colors.ink, fontSize: 17, fontWeight: '700', lineHeight: 24 },
  sectionHeading: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 },
  section: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: 10, padding: 18, gap: 14 },
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  button: { minHeight: 46, justifyContent: 'center', alignItems: 'center', paddingHorizontal: 15, paddingVertical: 11, borderRadius: 7, borderWidth: 1, borderColor: colors.line, backgroundColor: colors.white },
  buttonLabel: { fontSize: 13, fontWeight: '700', color: colors.ink, lineHeight: 20, textAlign: 'center' },
  primaryButton: { backgroundColor: colors.rust, borderColor: colors.rust },
  primaryButtonLabel: { color: colors.white },
  disabled: { opacity: 0.45 },
  pressed: { opacity: 0.75 },
  textButton: { minHeight: 44, justifyContent: 'center', alignItems: 'center', paddingHorizontal: 10 },
  linkText: { color: colors.rust, fontSize: 12, fontWeight: '600' },
  field: { gap: 7, minWidth: 0 },
  fieldLabel: { color: colors.ink, fontSize: 13, fontWeight: '600', lineHeight: 19 },
  input: { borderWidth: 1, borderColor: '#d9d5cd', backgroundColor: colors.white, borderRadius: 7, paddingHorizontal: 13, paddingVertical: 12, minHeight: 48, fontSize: 15, color: colors.ink },
  multilineInput: { minHeight: 96, textAlignVertical: 'top' },
  choiceRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  choice: { minHeight: 44, paddingHorizontal: 14, paddingVertical: 11, borderRadius: 7, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, justifyContent: 'center' },
  selectedChoice: { backgroundColor: colors.ink, borderColor: colors.ink },
  choiceText: { color: colors.muted, fontSize: 13, lineHeight: 20 },
  selectedChoiceText: { color: colors.white, fontWeight: '600' },
  catalogControls: { gap: 12, marginBottom: 16 },
  productRow: { flexDirection: 'row', gap: 14, alignItems: 'flex-start', paddingVertical: 18, borderTopWidth: 1, borderTopColor: colors.line },
  productName: { color: colors.ink, fontSize: 15, fontWeight: '600', lineHeight: 22 },
  productAction: { alignItems: 'flex-end', gap: 10, minWidth: 84 },
  productPrice: { color: colors.ink, fontSize: 15, fontWeight: '700', lineHeight: 22 },
  stock: { color: colors.green, fontSize: 12, marginTop: 6, lineHeight: 19 },
  lowStock: { color: colors.amber },
  empty: { paddingVertical: 26, gap: 8 },
  footerNote: { color: colors.muted, fontSize: 12, lineHeight: 20, paddingTop: 16 },
  cartRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 12, paddingVertical: 14, borderTopWidth: 1, borderTopColor: colors.line },
  quantityAndRemove: { alignItems: 'center' },
  quantityControl: { flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderColor: colors.line, borderRadius: 6 },
  quantityButton: { minHeight: 44, width: 44, alignItems: 'center', justifyContent: 'center' },
  quantityMark: { color: colors.ink, fontSize: 20 },
  quantityValue: { minWidth: 27, textAlign: 'center', color: colors.ink, fontSize: 14, fontWeight: '600' },
  totals: { gap: 10, paddingVertical: 8 },
  receiptRow: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12 },
  totalRow: { borderTopWidth: 1, borderTopColor: colors.line, paddingTop: 14, marginTop: 3 },
  totalLabel: { color: colors.ink, fontSize: 18, fontWeight: '700' },
  totalValue: { color: colors.ink, fontSize: 23, fontWeight: '700', letterSpacing: -0.5 },
  status: { borderRadius: 4, paddingHorizontal: 9, paddingVertical: 5, alignSelf: 'flex-start' },
  confirmedStatus: { backgroundColor: colors.greenLight },
  pendingStatus: { backgroundColor: colors.amberLight },
  statusText: { fontSize: 11, fontWeight: '600', lineHeight: 17 },
  confirmedText: { color: colors.green },
  pendingText: { color: colors.amber },
  connectionBanner: { paddingHorizontal: 20, paddingVertical: 14, backgroundColor: colors.amberLight, gap: 10, borderBottomWidth: 1, borderBottomColor: '#e8dcc0' },
  connectionError: { backgroundColor: colors.rustLight, borderBottomColor: '#ead2ca' },
  bannerTitle: { fontSize: 13, color: colors.ink, fontWeight: '700', lineHeight: 20, marginBottom: 4 },
  error: { color: colors.rust, backgroundColor: colors.rustLight, padding: 13, borderRadius: 6, fontSize: 13, lineHeight: 21 },
  notice: { color: colors.green, backgroundColor: colors.greenLight, padding: 13, borderRadius: 6, fontSize: 13, lineHeight: 21 },
  alertContainer: { paddingHorizontal: 20, gap: 8 },
  reason: { color: colors.amber, fontSize: 13, lineHeight: 21 },
  isolationNotice: { color: colors.rust, backgroundColor: colors.rustLight, fontSize: 12, lineHeight: 19, padding: 12, borderRadius: 6 },
  isolationStrip: { color: colors.rust, backgroundColor: colors.rustLight, fontSize: 11, lineHeight: 18, textAlign: 'center', paddingVertical: 5, paddingHorizontal: 12 },
  saleRow: { gap: 11, paddingVertical: 20, borderTopWidth: 1, borderTopColor: colors.line },
  deviceRow: { gap: 8, paddingVertical: 14, borderTopWidth: 1, borderTopColor: colors.line },
  guideText: { color: colors.muted, fontSize: 13, lineHeight: 22 },
  guideStep: { color: colors.ink, fontWeight: '600' },
  tabBar: { flexDirection: 'row', borderTopWidth: 1, borderTopColor: colors.line, backgroundColor: colors.white },
  tab: { flex: 1, minHeight: 64, justifyContent: 'center', alignItems: 'center', paddingVertical: 10, paddingHorizontal: 4, borderTopWidth: 3, borderTopColor: 'transparent', gap: 4 },
  activeTab: { borderTopColor: colors.rust, backgroundColor: '#fcfaf7' },
  tabLabel: { color: colors.muted, fontSize: 12, fontWeight: '600', textAlign: 'center' },
  activeTabLabel: { color: colors.rust },
  tabCount: { color: colors.muted, fontSize: 10, textAlign: 'center' },
  modalHeader: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 20, paddingVertical: 12, borderBottomColor: colors.line, borderBottomWidth: 1 },
  receiptPaper: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, padding: 20, gap: 10, borderRadius: 7 },
  receiptLogo: { alignSelf: 'center', width: 140, height: 80, marginBottom: 3 },
  receiptCompany: { color: colors.ink, textAlign: 'center', fontSize: 22, fontWeight: '700', lineHeight: 29 },
  receiptDetail: { color: colors.muted, textAlign: 'center', fontSize: 12, lineHeight: 19 },
  receiptNumber: { color: colors.ink, fontSize: 15, fontWeight: '600', marginTop: 6 },
  receiptStatus: { alignItems: 'center', alignSelf: 'center', marginTop: 4 },
  receiptLines: { borderTopWidth: 1, borderTopColor: colors.line, marginTop: 12 },
  receiptLine: { gap: 6, paddingVertical: 13, borderBottomWidth: 1, borderBottomColor: colors.line },
  receiptFooter: { textAlign: 'center', color: colors.muted, fontSize: 12, lineHeight: 20, borderTopWidth: 1, borderTopColor: colors.line, paddingTop: 18, marginTop: 10 },
});
