<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaleReturnService
{
    public function __construct(private SaleRefundAllocator $allocator) {}

    public function process(User $user, int $saleId, array $data, InventoryService $inventory): Sale
    {
        $partial = array_key_exists('items', $data);
        $reason = trim($data['reason'] ?? '') ?: ($partial ? 'Item return' : 'Full sale return');
        $key = $data['idempotency_key'] ?? null;
        if ($partial && ! $key) {
            throw ValidationException::withMessages(['idempotency_key' => 'Use a return confirmation key before returning selected items.']);
        }
        $requested = $partial ? array_map(fn (array $item): array => ['product_id' => (int) $item['product_id'], 'quantity' => (int) $item['quantity']], $data['items']) : null;
        if ($requested !== null) {
            usort($requested, fn (array $a, array $b): int => $a['product_id'] <=> $b['product_id']);
        }
        $hash = hash('sha256', json_encode(['actor_id' => $user->id, 'items' => $requested, 'reason' => $reason], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $saleId, $partial, $reason, $key, $requested, $hash, $inventory): Sale {
            Tenant::whereKey($user->tenant_id)->lockForUpdate()->firstOrFail();
            $actor = User::where('tenant_id', $user->tenant_id)->lockForUpdate()->findOrFail($user->id);
            abort_unless($actor->is_active && in_array($actor->role, ['admin', 'manager'], true), 403, 'Manager or administrator access is required to return items.');
            $sale = Sale::where('tenant_id', $user->tenant_id)->lockForUpdate()->findOrFail($saleId);
            if ($key) {
                $existing = SaleReturn::where('sale_id', $sale->id)->where('idempotency_key', $key)->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This return key was already used for a different return.');

                    return $sale->load(['cashier', 'returns.actor']);
                }
            }
            $items = $this->allocator->saleItems($sale);
            $quantities = $partial ? array_column($requested, 'quantity', 'product_id') : array_column(array_map(fn (array $item): array => ['product_id' => $item['product_id'], 'quantity' => $item['quantity'] - $item['returned_quantity']], $items), 'quantity', 'product_id');
            if ($partial && count($quantities) !== count($requested)) {
                throw ValidationException::withMessages(['items' => 'Choose each receipt item only once.']);
            }
            $receiptProducts = array_column($items, 'product_id');
            foreach ($quantities as $productId => $quantity) {
                if (! in_array((int) $productId, $receiptProducts, true)) {
                    throw ValidationException::withMessages(['items' => 'Only items from this receipt can be returned.']);
                }
                if ($partial && $quantity < 1) {
                    throw ValidationException::withMessages(['items' => 'Choose a positive return quantity.']);
                }
            }
            $refundItems = [];
            foreach ($items as &$item) {
                $quantity = $quantities[$item['product_id']] ?? 0;
                if ($quantity > $item['quantity'] - $item['returned_quantity']) {
                    throw ValidationException::withMessages(['items' => 'The return quantity exceeds the remaining units for '.$item['name'].'.']);
                }
                if ($quantity > 0) {
                    $refundItems[] = $this->allocator->refund($item, $quantity);
                    $item['returned_quantity'] += $quantity;
                }
            }
            unset($item);
            if ($refundItems === []) {
                return $sale->load(['cashier', 'returns.actor']);
            }
            $refund = SaleReturn::create([
                'tenant_id' => $user->tenant_id, 'sale_id' => $sale->id, 'user_id' => $actor->id,
                'number' => 'RT-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'idempotency_key' => $key, 'request_hash' => $hash, 'reason' => $reason, 'items' => $refundItems,
                'subtotal_cents' => array_sum(array_column($refundItems, 'subtotal_cents')),
                'discount_cents' => array_sum(array_column($refundItems, 'discount_cents')),
                'tax_cents' => array_sum(array_column($refundItems, 'tax_cents')),
                'total_cents' => array_sum(array_column($refundItems, 'total_cents')),
            ]);
            foreach ($refundItems as $item) {
                $product = Product::where('tenant_id', $user->tenant_id)->findOrFail($item['product_id']);
                $inventory->changeStock($actor, $product, $sale->location_id, $item['quantity'], 'return', $reason, $refund->number);
            }
            $fullyReturned = array_all($items, fn (array $item): bool => $item['returned_quantity'] === $item['quantity']);
            $sale->update(['items' => $items, 'returned_cents' => $sale->returned_cents + $refund->total_cents, 'status' => $fullyReturned ? 'returned' : 'partially_returned', 'returned_at' => $fullyReturned ? now() : null]);

            return $sale->refresh()->load(['cashier', 'returns.actor']);
        }, 5);
    }
}
