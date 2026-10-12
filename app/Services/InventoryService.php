<?php

namespace App\Services;

use App\Jobs\CheckLowStock;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public static function cents(int|float|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function percentage(int $amount, int|float|string $percent): int
    {
        $basisPoints = (int) round((float) $percent * 100);

        return intdiv($amount, 10000) * $basisPoints
            + intdiv(($amount % 10000) * $basisPoints + 5000, 10000);
    }

    /** Bulk callers may refresh channels and dispatch the stock check once before their outer transaction commits. */
    public function changeStock(User $user, Product $product, int $locationId, int $quantity, string $type, string $reason, ?string $reference = null, bool $refreshTracking = true): StockMovement
    {
        return DB::transaction(function () use ($user, $product, $locationId, $quantity, $type, $reason, $reference, $refreshTracking): StockMovement {
            Tenant::whereKey($user->tenant_id)->lockForUpdate()->firstOrFail();
            $actor = User::where('tenant_id', $user->tenant_id)->lockForUpdate()->findOrFail($user->id);
            abort_unless($actor->is_active, 403, 'Your account no longer has workspace access.');
            Location::where('tenant_id', $actor->tenant_id)->findOrFail($locationId);
            abort_unless($product->tenant_id === $actor->tenant_id, 404);
            $stock = Stock::firstOrCreate(['tenant_id' => $actor->tenant_id, 'product_id' => $product->id, 'location_id' => $locationId], ['quantity' => 0]);
            if ($quantity < 0) {
                $updated = Stock::whereKey($stock->id)->whereRaw('quantity >= reserved_quantity + ?', [abs($quantity)])->decrement('quantity', abs($quantity));
                if ($updated !== 1) {
                    $stock->refresh();
                    $available = max(0, $stock->quantity - $stock->reserved_quantity);
                    throw ValidationException::withMessages(['items' => "{$product->name} has {$available} available units. {$stock->reserved_quantity} units are reserved for online orders. Refresh stock or review pending orders before trying again."]);
                }
            } elseif ($quantity > 0) {
                Stock::whereKey($stock->id)->increment('quantity', $quantity);
            }
            $movement = StockMovement::create([
                'tenant_id' => $actor->tenant_id, 'product_id' => $product->id, 'location_id' => $locationId,
                'user_id' => $actor->id, 'quantity' => $quantity, 'type' => $type, 'reason' => $reason, 'reference' => $reference,
            ]);
            if ($refreshTracking) {
                app(CommerceService::class)->syncInventoryForTenant($actor->tenant_id);
                CheckLowStock::dispatch($actor->tenant_id)->afterCommit();
            }

            return $movement;
        }, 5);
    }

    public function sale(User $user, array $data): Sale
    {
        $priceTier = $data['price_tier'] ?? 'retail';
        $canonical = [
            'cashier_id' => $user->id,
            'location_id' => (int) $data['location_id'], 'customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            'items' => array_map(fn (array $item): array => ['product_id' => (int) $item['product_id'], 'quantity' => (int) $item['quantity']], $data['items']),
            'discount' => (int) round((float) ($data['discount_percent'] ?? 0) * 100),
            'tax' => isset($data['tax_percent']) ? (int) round((float) $data['tax_percent'] * 100) : null,
            'payments' => array_map(fn (array $payment): array => ['method' => $payment['method'], 'amount' => self::cents($payment['amount'])], $data['payments']),
        ];
        $legacyHash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
        $canonical['price_tier'] = $priceTier;
        $requestHash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $data, $priceTier, $requestHash, $legacyHash): Sale {
            $tenant = Tenant::whereKey($user->tenant_id)->lockForUpdate()->firstOrFail();
            $existing = Sale::where('tenant_id', $user->tenant_id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $sameRequest = hash_equals($existing->request_hash, $requestHash)
                    || ($priceTier === 'retail' && $existing->price_tier === 'retail' && hash_equals($existing->request_hash, $legacyHash));
                abort_unless($sameRequest, 409, 'This checkout key was already used for a different transaction.');

                return $existing->load(['cashier', 'returns.actor']);
            }
            Location::where('tenant_id', $user->tenant_id)->findOrFail($data['location_id']);
            if (isset($data['customer_id'])) {
                Customer::where('tenant_id', $user->tenant_id)->findOrFail($data['customer_id']);
            }
            $taxPercent = $tenant->settings['tax_percent'] ?? 0;
            if (isset($data['tax_percent']) && abs((float) $data['tax_percent'] - (float) $taxPercent) > 0.001) {
                throw ValidationException::withMessages(['tax_percent' => 'The tax rate changed. Refresh the register before completing this sale.']);
            }
            $items = [];
            $subtotal = 0;
            foreach ($data['items'] as $item) {
                $product = Product::where('tenant_id', $user->tenant_id)->findOrFail($item['product_id']);
                $unitPrice = $priceTier === 'wholesale' ? $product->wholesale_price_cents : $product->price_cents;
                if ($unitPrice === null) {
                    throw ValidationException::withMessages(['items' => 'Set a wholesale price for '.$product->name.' or use retail pricing.']);
                }
                $quantity = (int) $item['quantity'];
                $lineTotal = $unitPrice * $quantity;
                $items[] = ['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => $quantity, 'unit_price_cents' => $unitPrice, 'unit_cost_cents' => $product->cost_cents, 'line_total_cents' => $lineTotal];
                $subtotal += $lineTotal;
            }
            $discountPercent = $data['discount_percent'] ?? 0;
            $discount = $this->percentage($subtotal, $discountPercent);
            $tax = $this->percentage($subtotal - $discount, $taxPercent);
            $total = $subtotal - $discount + $tax;
            $payments = array_map(fn (array $payment): array => ['method' => $payment['method'], 'amount_cents' => self::cents($payment['amount'])], $data['payments']);
            $paid = array_sum(array_column($payments, 'amount_cents'));
            $nonCash = array_sum(array_column(array_filter($payments, fn (array $payment): bool => $payment['method'] !== 'cash'), 'amount_cents'));
            if ($paid < $total || $nonCash > $total) {
                throw ValidationException::withMessages(['payments' => 'Tender must cover the total. Card and wallet amounts cannot exceed the sale total.']);
            }
            $items = app(SaleRefundAllocator::class)->allocate($items, $subtotal, $discount, $tax);
            $sale = Sale::create([
                'tenant_id' => $user->tenant_id, 'location_id' => $data['location_id'], 'user_id' => $user->id,
                'customer_id' => $data['customer_id'] ?? null, 'number' => 'SR-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'idempotency_key' => $data['idempotency_key'], 'request_hash' => $requestHash,
                'items' => $items, 'payments' => $payments, 'subtotal_cents' => $subtotal,
                'discount_percent' => $discountPercent, 'discount_cents' => $discount,
                'tax_percent' => $taxPercent, 'tax_cents' => $tax, 'total_cents' => $total,
                'paid_cents' => $paid, 'change_cents' => $paid - $total, 'status' => 'completed',
                'price_tier' => $priceTier, 'returned_cents' => 0,
            ]);
            foreach ($items as $item) {
                $product = Product::where('tenant_id', $user->tenant_id)->findOrFail($item['product_id']);
                $this->changeStock($user, $product, $data['location_id'], -$item['quantity'], 'sale', 'Completed POS sale', $sale->number);
            }
            app(CashSessionService::class)->attachToSale($user, $sale);

            return $sale->load(['cashier', 'returns.actor']);
        }, 5);
    }

    public function returnSale(User $user, int $saleId, string|array $data = []): Sale
    {
        return app(SaleReturnService::class)->process($user, $saleId, is_string($data) ? ['reason' => $data] : $data, $this);
    }

    public function purchaseOrder(User $user, array $data): PurchaseOrder
    {
        $status = $data['status'] ?? 'ordered';
        if (! in_array($status, ['draft', 'ordered'], true)) {
            throw ValidationException::withMessages(['status' => 'Choose a draft or ordered purchase order.']);
        }
        $key = $data['idempotency_key'] ?? null;
        $requestedItems = array_map(fn (array $item): array => [
            'product_id' => (int) $item['product_id'], 'quantity' => (int) $item['quantity'],
            'cost_cents' => isset($item['cost']) ? self::cents($item['cost']) : null,
        ], $data['items']);
        usort($requestedItems, fn (array $left, array $right): int => $left['product_id'] <=> $right['product_id']);
        $requestHash = $key === null ? null : hash('sha256', json_encode([
            'actor_id' => $user->id, 'supplier_id' => (int) $data['supplier_id'], 'location_id' => (int) $data['location_id'],
            'status' => $status, 'reorder_rule_id' => isset($data['reorder_rule_id']) ? (int) $data['reorder_rule_id'] : null, 'items' => $requestedItems,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $data, $status, $key, $requestHash): PurchaseOrder {
            $tenant = Tenant::whereKey($user->tenant_id)->lockForUpdate()->firstOrFail();
            $user = User::where('tenant_id', $user->tenant_id)->lockForUpdate()->findOrFail($user->id);
            abort_unless($user->is_active && in_array($user->role, ['admin', 'manager'], true), 403, 'Manager or administrator access is required for purchasing.');
            if (! app(BillingService::class)->summary($tenant)['can_write']) {
                abort(response()->json(['message' => 'Subscription access has ended. Review Billing to continue purchasing.', 'code' => 'subscription_required'], 423));
            }
            if ($key !== null) {
                $existing = PurchaseOrder::where('tenant_id', $user->tenant_id)->where('creation_key', $key)->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->creation_hash, $requestHash), 409, 'This purchase key was already used for different order details or by another team member.');

                    return $existing->load('receipts.receiver:id,name');
                }
            }
            Supplier::where('tenant_id', $user->tenant_id)->findOrFail($data['supplier_id']);
            Location::where('tenant_id', $user->tenant_id)->findOrFail($data['location_id']);
            $items = [];
            $total = 0;
            foreach ($data['items'] as $item) {
                $product = Product::where('tenant_id', $user->tenant_id)->findOrFail($item['product_id']);
                $cost = isset($item['cost']) ? self::cents($item['cost']) : $product->cost_cents;
                $items[] = ['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => (int) $item['quantity'], 'received_quantity' => 0, 'unit_cost' => $cost / 100, 'line_total' => $cost * $item['quantity'] / 100];
                $total += $cost * $item['quantity'];
            }

            return PurchaseOrder::create([
                'tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'supplier_id' => $data['supplier_id'],
                'location_id' => $data['location_id'], 'items' => $items, 'total_cents' => $total,
                'number' => 'PO-'.now()->format('ymd').'-'.Str::upper(Str::random(8)), 'status' => $status,
                'creation_key' => $key, 'creation_hash' => $requestHash,
                'source' => isset($data['reorder_rule_id']) ? 'replenishment' : 'manual',
                'reorder_rule_id' => $data['reorder_rule_id'] ?? null, 'approved_at' => $status === 'ordered' ? now() : null,
            ]);
        }, 5);
    }

    public function receive(User $user, int $orderId, array $data = []): PurchaseOrder
    {
        $requested = isset($data['items']) ? array_map(fn (array $item): array => ['product_id' => (int) $item['product_id'], 'quantity' => (int) $item['quantity']], $data['items']) : null;
        if ($requested !== null) {
            usort($requested, fn (array $a, array $b): int => $a['product_id'] <=> $b['product_id']);
            if (empty($data['idempotency_key'])) {
                throw ValidationException::withMessages(['idempotency_key' => 'Use a unique receipt key when receiving part of an order.']);
            }
        }
        $key = $data['idempotency_key'] ?? 'legacy-full-remaining';
        $note = trim($data['note'] ?? '');
        $requestHash = hash('sha256', json_encode(['items' => $requested, 'note' => $note], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $orderId, $requested, $key, $note, $requestHash): PurchaseOrder {
            Tenant::whereKey($user->tenant_id)->lockForUpdate()->firstOrFail();
            $user = User::where('tenant_id', $user->tenant_id)->lockForUpdate()->findOrFail($user->id);
            abort_unless($user->is_active && in_array($user->role, ['admin', 'manager'], true), 403, 'Manager or administrator access is required for purchasing.');
            $order = PurchaseOrder::where('tenant_id', $user->tenant_id)->lockForUpdate()->findOrFail($orderId);
            $existing = PurchaseReceipt::where('tenant_id', $user->tenant_id)->where('purchase_order_id', $order->id)->where('idempotency_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $requestHash), 409, 'This receipt key was already used for a different delivery.');

                return $order->load('receipts.receiver:id,name');
            }
            if ($order->status === 'received' && $requested === null) {
                return $order->load('receipts.receiver:id,name');
            }
            abort_unless(in_array($order->status, ['ordered', 'partially_received'], true), 409, 'Approve the draft first. Fully received or cancelled orders cannot receive more stock.');
            $items = $order->receiptItems();
            $positions = array_flip(array_column($items, 'product_id'));
            $delivery = $requested ?? array_values(array_map(fn (array $item): array => ['product_id' => $item['product_id'], 'quantity' => $item['remaining_quantity']], array_filter($items, fn (array $item): bool => $item['remaining_quantity'] > 0)));
            if ($delivery === []) {
                throw ValidationException::withMessages(['items' => 'This order has no remaining units to receive.']);
            }
            $receivedItems = [];
            foreach ($delivery as $index => $line) {
                $position = $positions[$line['product_id']] ?? null;
                if ($position === null || $line['quantity'] < 1 || $line['quantity'] > $items[$position]['remaining_quantity']) {
                    throw ValidationException::withMessages(['items.'.$index.'.quantity' => 'Receive only products on this order and no more than their remaining quantities.']);
                }
                $product = Product::where('tenant_id', $user->tenant_id)->findOrFail($line['product_id']);
                $receivedItems[] = ['product_id' => $product->id, 'name' => $items[$position]['name'], 'sku' => $items[$position]['sku'], 'quantity' => $line['quantity']];
                $items[$position]['received_quantity'] += $line['quantity'];
                $items[$position]['remaining_quantity'] -= $line['quantity'];
            }
            $receipt = PurchaseReceipt::create([
                'tenant_id' => $user->tenant_id, 'purchase_order_id' => $order->id, 'user_id' => $user->id,
                'number' => 'GR-'.now()->format('ymd').'-'.Str::upper(Str::random(8)), 'idempotency_key' => $key,
                'request_hash' => $requestHash, 'items' => $receivedItems, 'note' => $note !== '' ? $note : null,
            ]);
            $complete = array_sum(array_column($items, 'remaining_quantity')) === 0;
            $order->update([
                'items' => array_map(function (array $item): array {
                    unset($item['remaining_quantity']);

                    return $item;
                }, $items),
                'status' => $complete ? 'received' : 'partially_received', 'received_at' => $complete ? now() : null,
            ]);
            foreach ($receivedItems as $item) {
                $product = Product::where('tenant_id', $user->tenant_id)->findOrFail($item['product_id']);
                $this->changeStock($user, $product, $order->location_id, $item['quantity'], 'receipt', $note !== '' ? $note : 'Purchase order delivery received', $order->number.'/'.$receipt->number);
            }

            return $order->refresh()->load('receipts.receiver:id,name');
        }, 5);
    }
}
