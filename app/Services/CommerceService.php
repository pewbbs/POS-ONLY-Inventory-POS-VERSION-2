<?php

namespace App\Services;

use App\Jobs\CheckLowStock;
use App\Models\ChannelListing;
use App\Models\ChannelOrder;
use App\Models\ChannelSync;
use App\Models\CommerceChannel;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommerceService
{
    public function __construct(private InventoryService $inventory, private SaleRefundAllocator $allocator, private BillingService $billing) {}

    public function simulationEnabled(): bool
    {
        return app()->environment(['local', 'testing']) && (bool) config('commerce.simulations_enabled');
    }

    private function requireSimulation(): void
    {
        abort_unless($this->simulationEnabled(), 404);
    }

    private function activeManager(User $actor): User
    {
        $user = User::where('tenant_id', $actor->tenant_id)->lockForUpdate()->findOrFail($actor->id);
        abort_unless($user->is_active && in_array($user->role, ['admin', 'manager'], true), 403, 'Manager or administrator access is required for channels.');

        return $user;
    }

    private function mutate(User $actor, callable $action, bool $requiresAccess = true): mixed
    {
        return DB::transaction(function () use ($actor, $action, $requiresAccess): mixed {
            $tenant = Tenant::whereKey($actor->tenant_id)->lockForUpdate()->firstOrFail();
            $user = $this->activeManager($actor);
            if ($requiresAccess) {
                $subscription = $this->billing->summary($tenant);
                if (! $subscription['can_write']) {
                    abort(response()->json(['message' => 'Your subscription access has expired. Review Billing to continue business changes.', 'code' => 'subscription_required', 'subscription' => $subscription], 423));
                }
            }

            return $action($user, $tenant);
        }, 5);
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function createChannel(User $actor, array $data): CommerceChannel
    {
        $this->requireSimulation();

        return $this->mutate($actor, function (User $user, Tenant $tenant) use ($data): CommerceChannel {
            $hash = $this->hash(['actor_id' => $user->id, 'name' => trim($data['name']), 'kind' => $data['kind'], 'location_id' => (int) $data['location_id']]);
            $existing = CommerceChannel::where('tenant_id', $tenant->id)->where('creation_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->creation_hash, $hash), 409, 'This channel key was already used for different channel details.');

                return $existing;
            }
            Location::where('tenant_id', $tenant->id)->findOrFail($data['location_id']);
            $channel = CommerceChannel::create(['tenant_id' => $tenant->id, 'location_id' => (int) $data['location_id'], 'user_id' => $user->id,
                'name' => trim($data['name']), 'kind' => $data['kind'], 'enabled' => true, 'simulate_failure' => false,
                'creation_key' => $data['idempotency_key'], 'creation_hash' => $hash]);
            $this->refreshChannel($channel);

            return $channel->refresh();
        });
    }

    public function updateChannel(User $actor, int $channelId, array $data): CommerceChannel
    {
        $this->requireSimulation();

        return $this->mutate($actor, function (User $user, Tenant $tenant) use ($channelId, $data): CommerceChannel {
            $channel = CommerceChannel::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($channelId);
            if (isset($data['name'])) {
                $channel->name = trim($data['name']);
            }
            if (array_key_exists('simulate_failure', $data) && $channel->simulate_failure !== (bool) $data['simulate_failure']) {
                $channel->simulate_failure = (bool) $data['simulate_failure'];
                $channel->pending_sync = $channel->enabled;
                $channel->sync_state = $channel->enabled ? 'stale' : 'paused';
            }
            $channel->save();

            return $channel;
        });
    }

    public function setEnabled(User $actor, int $channelId, bool $enabled): CommerceChannel
    {
        if ($enabled) {
            $this->requireSimulation();
        }

        return $this->mutate($actor, function (User $user, Tenant $tenant) use ($channelId, $enabled): CommerceChannel {
            $channel = CommerceChannel::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($channelId);
            $channel->update(['enabled' => $enabled]);
            $this->refreshChannel($channel);

            return $channel->refresh();
        }, $enabled);
    }

    public function sync(User $actor, int $channelId, string $key): array
    {
        $this->requireSimulation();

        return $this->mutate($actor, function (User $user, Tenant $tenant) use ($channelId, $key): array {
            $channel = CommerceChannel::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($channelId);
            $hash = $this->hash(['actor_id' => $user->id, 'channel_id' => $channel->id]);
            $existing = ChannelSync::where('commerce_channel_id', $channel->id)->where('idempotency_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This sync key was already used by another team member.');

                return ['channel' => $this->serializeChannel($channel), 'sync' => $this->serializeSync($existing)];
            }
            $success = $this->refreshChannel($channel, true);
            $sync = ChannelSync::create(['tenant_id' => $tenant->id, 'commerce_channel_id' => $channel->id, 'user_id' => $user->id,
                'idempotency_key' => $key, 'request_hash' => $hash, 'status' => $success ? 'succeeded' : 'failed',
                'message' => $success ? ($channel->enabled ? 'Local inventory snapshot refreshed.' : 'Paused channel publishes zero units.') : 'Simulated sync failure. No external request was sent.',
                'listing_count' => ChannelListing::where('commerce_channel_id', $channel->id)->count()]);

            return ['channel' => $this->serializeChannel($channel->refresh()), 'sync' => $this->serializeSync($sync)];
        });
    }

    /** Refresh within the same tenant transaction as each inventory mutation. */
    public function syncInventoryForTenant(int $tenantId): void
    {
        DB::transaction(function () use ($tenantId): void {
            Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            CommerceChannel::where('tenant_id', $tenantId)->orderBy('id')->each(function (CommerceChannel $channel): void {
                $this->refreshChannel($channel);
            });
        }, 5);
    }

    private function refreshChannel(CommerceChannel $channel, bool $manual = false): bool
    {
        if ($channel->enabled && (! $this->simulationEnabled() || $channel->simulate_failure)) {
            $channel->fill(['pending_sync' => true, 'sync_state' => $this->simulationEnabled() ? 'failed' : 'stale',
                'last_error' => $this->simulationEnabled() ? 'Simulated sync failure. Published stock may be stale.' : 'Local simulation is unavailable in this environment.']);
            if ($channel->isDirty()) {
                $channel->save();
            }

            return false;
        }
        $stocks = Stock::where('tenant_id', $channel->tenant_id)->where('location_id', $channel->location_id)->get()->keyBy('product_id');
        $listings = ChannelListing::where('commerce_channel_id', $channel->id)->get()->keyBy('product_id');
        $changed = false;
        Product::where('tenant_id', $channel->tenant_id)->orderBy('id')->each(function (Product $product) use ($channel, $stocks, $listings, &$changed): void {
            $stock = $stocks->get($product->id);
            $quantity = $channel->enabled ? max(0, ($stock?->quantity ?? 0) - ($stock?->reserved_quantity ?? 0)) : 0;
            $listing = $listings->get($product->id) ?? new ChannelListing;
            $listing->fill(['tenant_id' => $channel->tenant_id, 'commerce_channel_id' => $channel->id, 'product_id' => $product->id,
                'sku' => $product->sku, 'name' => $product->name, 'price_cents' => $product->price_cents, 'published_quantity' => $quantity]);
            if ($listing->isDirty()) {
                $listing->save();
                $changed = true;
            }
        });
        $wasStale = $channel->sync_state !== ($channel->enabled ? 'synced' : 'paused') || $channel->pending_sync;
        $channel->fill(['sync_state' => $channel->enabled ? 'synced' : 'paused', 'pending_sync' => false, 'last_error' => null]);
        if ($changed || $manual || $wasStale || ! $channel->last_synced_at) {
            $channel->last_synced_at = now();
        }
        if ($channel->isDirty()) {
            $channel->save();
        }

        return true;
    }

    public function createOrder(User $actor, int $channelId, array $data): ChannelOrder
    {
        $this->requireSimulation();

        return $this->mutate($actor, function (User $user, Tenant $tenant) use ($channelId, $data): ChannelOrder {
            $channel = CommerceChannel::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($channelId);
            $requested = array_map(fn (array $item): array => ['sku' => trim($item['sku']), 'quantity' => (int) $item['quantity']], $data['items']);
            usort($requested, fn (array $a, array $b): int => strcmp($a['sku'], $b['sku']));
            if (count(array_unique(array_column($requested, 'sku'))) !== count($requested)) {
                throw ValidationException::withMessages(['items' => 'Choose each SKU once.']);
            }
            $reference = trim($data['external_reference']);
            $hash = $this->hash(['actor_id' => $user->id, 'channel_id' => $channel->id, 'external_reference' => $reference, 'items' => $requested]);
            $existing = ChannelOrder::where('commerce_channel_id', $channel->id)->where(function ($query) use ($data, $reference): void {
                $query->where('idempotency_key', $data['idempotency_key'])->orWhere('external_reference', $reference);
            })->first();
            if ($existing) {
                abort_unless($existing->idempotency_key === $data['idempotency_key'] && $existing->external_reference === $reference && hash_equals($existing->request_hash, $hash), 409, 'This reference or order key was already used for a different order.');

                return $existing;
            }
            abort_unless($channel->enabled, 422, 'Resume this channel before creating an order.');
            $items = [];
            $productIds = [];
            $subtotal = 0;
            foreach ($requested as $line) {
                $product = Product::where('tenant_id', $tenant->id)->where('sku', $line['sku'])->first();
                if (! $product) {
                    throw ValidationException::withMessages(['items' => 'Choose a SKU from this tenant catalog.']);
                }
                if (isset($productIds[$product->id])) {
                    throw ValidationException::withMessages(['items' => 'Choose each catalog product once. These SKUs resolve to the same product.']);
                }
                $productIds[$product->id] = true;
                $quantity = $line['quantity'];
                if ($quantity < 1) {
                    throw ValidationException::withMessages(['items' => 'Choose positive order quantities.']);
                }
                $stock = Stock::where('tenant_id', $tenant->id)->where('location_id', $channel->location_id)->where('product_id', $product->id)->lockForUpdate()->first();
                if (! $stock || $stock->quantity - $stock->reserved_quantity < $quantity) {
                    throw ValidationException::withMessages(['items' => 'Not enough available stock for '.$product->name.'. Pending channel orders have already reserved their units.']);
                }
                $updated = Stock::whereKey($stock->id)->whereRaw('quantity >= reserved_quantity + ?', [$quantity])->increment('reserved_quantity', $quantity);
                if ($updated !== 1) {
                    throw ValidationException::withMessages(['items' => 'Available stock changed. Refresh the channel before creating this order.']);
                }
                $lineTotal = $product->price_cents * $quantity;
                $items[] = ['product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'quantity' => $quantity,
                    'unit_price_cents' => $product->price_cents, 'unit_cost_cents' => $product->cost_cents, 'line_total_cents' => $lineTotal];
                $subtotal += $lineTotal;
            }
            $taxPercent = $tenant->settings['tax_percent'] ?? 0;
            $basisPoints = (int) round((float) $taxPercent * 100);
            $tax = intdiv($subtotal, 10000) * $basisPoints + intdiv(($subtotal % 10000) * $basisPoints + 5000, 10000);
            $items = $this->allocator->allocate($items, $subtotal, 0, $tax);
            $order = ChannelOrder::create(['tenant_id' => $tenant->id, 'commerce_channel_id' => $channel->id, 'location_id' => $channel->location_id,
                'user_id' => $user->id, 'status' => 'pending', 'external_reference' => $reference, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                'items' => $items, 'subtotal_cents' => $subtotal, 'discount_cents' => 0, 'tax_percent' => $taxPercent, 'tax_cents' => $tax, 'total_cents' => $subtotal + $tax]);
            $this->syncInventoryForTenant($tenant->id);
            CheckLowStock::dispatch($tenant->id)->afterCommit();

            return $order;
        });
    }

    private function releaseReservations(ChannelOrder $order): void
    {
        foreach ($order->items as $item) {
            $updated = Stock::where('tenant_id', $order->tenant_id)->where('location_id', $order->location_id)->where('product_id', $item['product_id'])
                ->where('reserved_quantity', '>=', $item['quantity'])->decrement('reserved_quantity', $item['quantity']);
            abort_unless($updated === 1, 409, 'The reservation changed. Refresh before processing this order.');
        }
    }

    public function importOrder(User $actor, int $orderId): ChannelOrder
    {
        $this->requireSimulation();

        return $this->mutate($actor, function (User $user, Tenant $tenant) use ($orderId): ChannelOrder {
            $order = ChannelOrder::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($orderId);
            if ($order->status === 'imported') {
                return $order;
            }
            abort_unless($order->status === 'pending', 409, 'A canceled order cannot be imported.');
            $this->releaseReservations($order);
            $sale = Sale::create(['tenant_id' => $tenant->id, 'location_id' => $order->location_id, 'user_id' => $user->id,
                'number' => 'SR-'.now()->format('ymd').'-'.Str::upper(Str::random(10)), 'status' => 'completed',
                'source' => 'channel', 'channel_id' => $order->commerce_channel_id, 'price_tier' => 'retail',
                'idempotency_key' => 'commerce-'.Str::uuid(), 'request_hash' => $order->request_hash, 'items' => $order->items,
                'payments' => [['method' => 'card', 'amount_cents' => $order->total_cents, 'simulated' => true, 'source' => 'commerce_simulation', 'external_reference' => $order->external_reference]],
                'subtotal_cents' => $order->subtotal_cents, 'discount_percent' => 0, 'discount_cents' => 0,
                'tax_percent' => $order->tax_percent, 'tax_cents' => $order->tax_cents, 'total_cents' => $order->total_cents,
                'paid_cents' => $order->total_cents, 'change_cents' => 0, 'returned_cents' => 0]);
            foreach ($order->items as $item) {
                $product = Product::where('tenant_id', $tenant->id)->findOrFail($item['product_id']);
                $this->inventory->changeStock($user, $product, $order->location_id, -$item['quantity'], 'sale', 'Imported local simulated channel order', $sale->number);
            }
            $order->update(['status' => 'imported', 'sale_id' => $sale->id, 'imported_at' => now()]);
            $this->syncInventoryForTenant($tenant->id);

            return $order;
        });
    }

    public function cancelOrder(User $actor, int $orderId): ChannelOrder
    {
        return $this->mutate($actor, function (User $user, Tenant $tenant) use ($orderId): ChannelOrder {
            $order = ChannelOrder::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($orderId);
            if ($order->status === 'canceled') {
                return $order;
            }
            abort_unless($order->status === 'pending', 409, 'Imported orders must be refunded through their sale receipt.');
            $this->releaseReservations($order);
            $order->update(['status' => 'canceled', 'canceled_at' => now()]);
            $this->syncInventoryForTenant($tenant->id);
            CheckLowStock::dispatch($tenant->id)->afterCommit();

            return $order;
        }, false);
    }

    public function state(User $actor): array
    {
        $user = User::where('tenant_id', $actor->tenant_id)->findOrFail($actor->id);
        abort_unless($user->is_active && in_array($user->role, ['admin', 'manager'], true), 403);
        $stocksByLocation = Stock::where('tenant_id', $actor->tenant_id)->get()->groupBy('location_id');
        $handledIds = ChannelOrder::where('tenant_id', $actor->tenant_id)->where('status', '!=', 'pending')->latest('id')->limit(200)->pluck('id');
        $orders = ChannelOrder::where('tenant_id', $actor->tenant_id)->where(function ($query) use ($handledIds): void {
            $query->where('status', 'pending')->orWhereIn('id', $handledIds);
        })->with(['channel:id,name', 'actor:id,name', 'sale:id,number'])->latest('id')->get();

        return ['simulation_enabled' => $this->simulationEnabled(),
            'notice' => 'Local commerce simulation. No store, marketplace, social network or payment provider is connected. Enabled channels may accept simulated orders even when published stock is stale; central reservations prevent overselling.',
            'channels' => CommerceChannel::where('tenant_id', $actor->tenant_id)->with(['listings', 'creator:id,name'])->orderBy('id')->get()->map(fn (CommerceChannel $channel): array => $this->serializeChannel($channel, $stocksByLocation->get($channel->location_id, collect())->keyBy('product_id')))->all(),
            'orders' => $orders->map(fn (ChannelOrder $order): array => $this->serializeOrder($order))->all(),
            'order_total' => ChannelOrder::where('tenant_id', $actor->tenant_id)->count(),
            'pending_order_count' => ChannelOrder::where('tenant_id', $actor->tenant_id)->where('status', 'pending')->count(),
            'syncs' => ChannelSync::where('tenant_id', $actor->tenant_id)->with(['channel:id,name', 'actor:id,name'])->latest('id')->limit(200)->get()->map(fn (ChannelSync $sync): array => $this->serializeSync($sync))->all(), 'history_limit' => 200];
    }

    public function serializeChannel(CommerceChannel $channel, ?Collection $stocks = null): array
    {
        $channel->loadMissing(['listings', 'creator:id,name']);
        $stocks ??= Stock::where('tenant_id', $channel->tenant_id)->where('location_id', $channel->location_id)->get()->keyBy('product_id');

        return ['id' => $channel->id, 'name' => $channel->name, 'kind' => $channel->kind, 'location_id' => $channel->location_id,
            'enabled' => $channel->enabled, 'simulate_failure' => $channel->simulate_failure, 'is_simulated' => true, 'simulation_enabled' => $this->simulationEnabled(),
            'sync_state' => $channel->sync_state, 'pending_sync' => $channel->pending_sync, 'last_error' => $channel->last_error,
            'last_synced_at' => $channel->last_synced_at?->toIso8601String(), 'actor_name' => $channel->creator?->name,
            'listings' => $channel->listings->map(function (ChannelListing $listing) use ($stocks): array {
                $stock = $stocks->get($listing->product_id);

                return ['id' => $listing->id, 'product_id' => $listing->product_id, 'sku' => $listing->sku, 'name' => $listing->name,
                    'price' => $listing->price_cents / 100, 'published_quantity' => $listing->published_quantity,
                    'on_hand' => $stock?->quantity ?? 0, 'reserved_quantity' => $stock?->reserved_quantity ?? 0,
                    'available_quantity' => max(0, ($stock?->quantity ?? 0) - ($stock?->reserved_quantity ?? 0))];
            })->all()];
    }

    public function serializeOrder(ChannelOrder $order): array
    {
        $order->loadMissing(['channel:id,name', 'actor:id,name', 'sale:id,number']);

        return ['id' => $order->id, 'channel_id' => $order->commerce_channel_id, 'channel_name' => $order->channel?->name,
            'location_id' => $order->location_id, 'actor_name' => $order->actor?->name, 'external_reference' => $order->external_reference,
            'status' => $order->status, 'payment_status' => 'simulated_paid', 'sale_id' => $order->sale_id, 'sale_number' => $order->sale?->number,
            'subtotal' => $order->subtotal_cents / 100, 'discount' => 0, 'tax_percent' => (float) $order->tax_percent, 'tax' => $order->tax_cents / 100, 'total' => $order->total_cents / 100,
            'created_at' => $order->created_at->toIso8601String(), 'imported_at' => $order->imported_at?->toIso8601String(), 'canceled_at' => $order->canceled_at?->toIso8601String(),
            'items' => array_map(fn (array $item): array => ['product_id' => $item['product_id'], 'sku' => $item['sku'], 'name' => $item['name'], 'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price_cents'] / 100, 'line_total' => $item['line_total_cents'] / 100, 'discount' => $item['discount_cents'] / 100,
                'tax' => $item['tax_cents'] / 100, 'total' => $item['total_cents'] / 100], $order->items)];
    }

    public function serializeSync(ChannelSync $sync): array
    {
        $sync->loadMissing(['channel:id,name', 'actor:id,name']);

        return ['id' => $sync->id, 'channel_id' => $sync->commerce_channel_id, 'channel_name' => $sync->channel?->name,
            'actor_name' => $sync->actor?->name, 'status' => $sync->status, 'message' => $sync->message,
            'listing_count' => $sync->listing_count, 'created_at' => $sync->created_at->toIso8601String()];
    }
}
