<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReorderRule;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ReplenishmentService
{
    public function __construct(private InventoryService $inventory, private BillingService $billing) {}

    public function generateForTenant(int $tenantId, ?User $actor = null): Collection
    {
        $orders = new Collection;
        foreach (ReorderRule::where('tenant_id', $tenantId)->where('enabled', true)->lazyById(100) as $candidate) {
            $order = DB::transaction(function () use ($tenantId, $candidate, $actor): ?PurchaseOrder {
                $tenant = Tenant::whereKey($tenantId)->lockForUpdate()->first();
                if (! $tenant || ! $this->billing->summary($tenant)['can_write']) {
                    return null;
                }
                $rule = ReorderRule::where('tenant_id', $tenantId)->whereKey($candidate->id)->lockForUpdate()->first();
                if (! $rule || ! $rule->enabled) {
                    return null;
                }
                $product = Product::where('tenant_id', $tenantId)->whereKey($rule->product_id)->lockForUpdate()->first();
                if (! $product || $rule->target_quantity <= $product->reorder_level ||
                    ! Location::where('tenant_id', $tenantId)->whereKey($rule->location_id)->exists() ||
                    ! Supplier::where('tenant_id', $tenantId)->whereKey($rule->supplier_id)->exists()) {
                    return null;
                }
                $users = User::where('tenant_id', $tenantId)->where('is_active', true)->whereIn('role', ['admin', 'manager']);
                $user = $actor ? (clone $users)->whereKey($actor->id)->first() : (clone $users)->whereKey($rule->user_id)->first();
                abort_if($actor && ! $user, 403, 'Manager or administrator access is required to generate purchase drafts.');
                $user ??= $actor ? null : $users->orderBy('id')->first();
                if (! $user) {
                    return null;
                }
                Stock::firstOrCreate(['tenant_id' => $tenantId, 'product_id' => $product->id, 'location_id' => $rule->location_id], ['quantity' => 0]);
                $stock = Stock::where('tenant_id', $tenantId)->where('product_id', $product->id)->where('location_id', $rule->location_id)->lockForUpdate()->firstOrFail();
                $available = max(0, $stock->quantity - $stock->reserved_quantity);
                if ($available > $product->reorder_level) {
                    return null;
                }
                $pending = 0;
                foreach (PurchaseOrder::where('tenant_id', $tenantId)->where('location_id', $rule->location_id)->whereIn('status', ['draft', 'ordered', 'partially_received'])->select(['id', 'status', 'items'])->lazyById(100) as $outstanding) {
                    foreach ($outstanding->receiptItems() as $item) {
                        if ((int) $item['product_id'] === $product->id) {
                            $pending += $item['remaining_quantity'];
                        }
                    }
                }
                $quantity = $rule->target_quantity - $available - $pending;
                if ($quantity <= 0) {
                    return null;
                }

                return $this->inventory->purchaseOrder($user, [
                    'supplier_id' => $rule->supplier_id, 'location_id' => $rule->location_id,
                    'status' => 'draft', 'reorder_rule_id' => $rule->id,
                    'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
                ]);
            }, 5);
            if ($order) {
                $orders->push($order);
            }
        }

        return $orders;
    }
}
