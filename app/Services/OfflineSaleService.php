<?php

namespace App\Services;

use App\Http\Resources\ProductResource;
use App\Models\CashSession;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class OfflineSaleService
{
    public function __construct(private InventoryService $inventory, private BillingService $billing) {}

    public function snapshot(User $user, Request $request): array
    {
        $actor = $user->fresh();
        abort_unless($actor?->is_active && in_array($actor->role, ['admin', 'manager', 'cashier'], true), 403, 'Your account no longer has workspace access.');
        $tenant = $actor->tenant;
        abort_unless($tenant, 403, 'Your account has no active workspace.');
        $capturedAt = now();
        $expiresAt = $capturedAt->copy()->addSeconds((int) config('offline.cache_seconds'));
        $products = Product::where('tenant_id', $actor->tenant_id)->with([
            'stocks' => fn (Relation $query): Relation => $query->where('tenant_id', $actor->tenant_id),
            'family' => fn (Relation $query): Relation => $query->where('tenant_id', $actor->tenant_id),
        ])->orderBy('id')->get();
        $catalog = ProductResource::collection($products)->resolve($request);

        return [
            'version' => 1,
            'captured_at' => $capturedAt->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'permit' => $this->permit($actor, $capturedAt->timestamp, $expiresAt->timestamp),
            'user' => ['id' => $actor->id, 'role' => $actor->role],
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'locations' => Location::where('tenant_id', $tenant->id)->orderBy('id')->get(['id', 'name', 'type'])->toArray(),
            'products' => array_map(fn (array $product): array => Arr::only($product, ['id', 'name', 'sku', 'barcode', 'category', 'price', 'wholesale_price', 'reorder_level', 'color', 'variant', 'product_family_id', 'family_name', 'variant_options', 'stocks']), $catalog),
            'settings' => [
                'tax_percent' => (float) ($tenant->settings['tax_percent'] ?? 0),
                'receipt_footer' => $tenant->settings['receipt_footer'] ?? 'Thank you for shopping with us.',
            ],
            'cash_sessions' => CashSession::where('tenant_id', $tenant->id)->where('user_id', $actor->id)->where('status', 'open')->orderBy('id')->get(['id', 'location_id'])->toArray(),
            'can_write' => $this->accessSummary($tenant)['can_write'],
        ];
    }

    public function sync(User $user, array $data): Sale
    {
        $canonical = $this->canonical($user, $data);
        $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
        $saleData = Arr::only($data, ['location_id', 'price_tier', 'items', 'discount_percent', 'tax_percent', 'payments', 'idempotency_key']);

        return DB::transaction(function () use ($user, $data, $canonical, $hash, $saleData): Sale {
            $tenant = Tenant::whereKey($user->tenant_id)->lockForUpdate()->firstOrFail();
            $actor = User::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($user->id);
            abort_unless($actor->is_active && in_array($actor->role, ['admin', 'manager', 'cashier'], true), 403, 'Your account no longer has workspace access.');
            $existing = Sale::where('tenant_id', $tenant->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless(is_string($existing->offline_request_hash) && hash_equals($existing->offline_request_hash, $hash), 409, 'This checkout key was already used for a different transaction.');

                return $this->inventory->sale($actor, $saleData);
            }
            $this->validatePermit($actor, $data['permit'], Carbon::parse($canonical['recorded_at']));
            $summary = $this->accessSummary($tenant);
            abort_unless($summary['can_write'], 423, $summary['operations_paused'] ? 'Company operations are paused. Reconnect and ask an administrator to review access.' : 'Subscription access has ended. Reconnect and ask an administrator to review billing.');
            Location::where('tenant_id', $tenant->id)->findOrFail($data['location_id']);
            $this->validateQuote($tenant, $actor, $data);
            $sale = $this->inventory->sale($actor, $saleData);
            $sale->update([
                'offline_recorded_at' => Carbon::parse($canonical['recorded_at'])->setTimezone(config('app.timezone')),
                'offline_synced_at' => now(),
                'offline_request_hash' => $hash,
            ]);

            return $sale;
        }, 5);
    }

    private function canonical(User $user, array $data): array
    {
        return [
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'location_id' => (int) $data['location_id'],
            'price_tier' => $data['price_tier'],
            'items' => array_map(fn (array $item): array => ['product_id' => (int) $item['product_id'], 'quantity' => (int) $item['quantity']], $data['items']),
            'discount_basis_points' => (int) round((float) $data['discount_percent'] * 100),
            'tax_basis_points' => (int) round((float) $data['tax_percent'] * 100),
            'payments' => array_map(fn (array $payment): array => ['method' => $payment['method'], 'amount_cents' => InventoryService::cents($payment['amount'])], $data['payments']),
            'idempotency_key' => $data['idempotency_key'],
            'permit' => $data['permit'],
            'recorded_at' => Carbon::parse($data['recorded_at'])->utc()->toISOString(),
            'expected' => [
                'items' => array_map(fn (array $item): array => ['product_id' => (int) $item['product_id'], 'unit_price_cents' => (int) $item['unit_price_cents']], $data['expected']['items']),
                'subtotal_cents' => (int) $data['expected']['subtotal_cents'],
                'discount_cents' => (int) $data['expected']['discount_cents'],
                'tax_cents' => (int) $data['expected']['tax_cents'],
                'total_cents' => (int) $data['expected']['total_cents'],
                'cash_session_id' => isset($data['expected']['cash_session_id']) ? (int) $data['expected']['cash_session_id'] : null,
            ],
        ];
    }

    private function accessSummary(Tenant $tenant): array
    {
        $subscription = Subscription::where('tenant_id', $tenant->id)->first();

        return $subscription === null
            ? ['can_write' => false, 'operations_paused' => $tenant->operations_paused]
            : $this->billing->describeSubscription($tenant, $subscription);
    }

    private function validateQuote(Tenant $tenant, User $actor, array $data): void
    {
        $expected = $data['expected'];
        $quotedItems = collect($expected['items'])->keyBy('product_id');
        abort_unless(count($data['items']) === $quotedItems->count(), 409, 'The offline price quote does not match the queued items. Review this sale before syncing.');
        $subtotal = 0;
        foreach ($data['items'] as $item) {
            $product = Product::where('tenant_id', $tenant->id)->findOrFail($item['product_id']);
            $price = $data['price_tier'] === 'wholesale' ? $product->wholesale_price_cents : $product->price_cents;
            $quote = $quotedItems->get($product->id);
            abort_unless($price !== null && $quote !== null && (int) $quote['unit_price_cents'] === (int) $price, 409, 'A product price changed. Review the queued cash sale; its original tender has not been changed.');
            $subtotal += $price * (int) $item['quantity'];
        }
        $taxPercent = (float) ($tenant->settings['tax_percent'] ?? 0);
        abort_unless((int) round($taxPercent * 100) === (int) round((float) $data['tax_percent'] * 100), 409, 'The tax rate changed. Review the queued cash sale before syncing.');
        $discount = $this->percentage($subtotal, $data['discount_percent']);
        $tax = $this->percentage($subtotal - $discount, $taxPercent);
        $actual = ['subtotal_cents' => $subtotal, 'discount_cents' => $discount, 'tax_cents' => $tax, 'total_cents' => $subtotal - $discount + $tax];
        foreach ($actual as $key => $amount) {
            abort_unless((int) $expected[$key] === $amount, 409, 'The queued cash sale totals changed. Review this sale before syncing.');
        }
        $session = CashSession::where('tenant_id', $tenant->id)->where('user_id', $actor->id)->where('location_id', $data['location_id'])->where('status', 'open')->lockForUpdate()->first();
        $expectedSession = isset($expected['cash_session_id']) ? (int) $expected['cash_session_id'] : null;
        abort_unless($session?->id === $expectedSession, 409, 'The cash shift changed or closed. Review the queued sale before syncing; it has not been assigned to a different shift.');
    }

    private function percentage(int $amount, int|float|string $percent): int
    {
        $basisPoints = (int) round((float) $percent * 100);

        return intdiv($amount, 10000) * $basisPoints + intdiv(($amount % 10000) * $basisPoints + 5000, 10000);
    }

    private function permit(User $user, int $issuedAt, int $expiresAt): string
    {
        $claims = $this->encode(json_encode(['version' => 1, 'tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'issued_at' => $issuedAt, 'expires_at' => $expiresAt], JSON_THROW_ON_ERROR));

        return $claims.'.'.$this->encode(hash_hmac('sha256', "posonly-offline-permit-v1\0".$claims, $this->signingKey(), true));
    }

    private function validatePermit(User $user, string $permit, Carbon $recordedAt): void
    {
        $parts = explode('.', $permit);
        abort_unless(count($parts) === 2 && preg_match('/^[A-Za-z0-9_-]+$/D', $parts[0]) && preg_match('/^[A-Za-z0-9_-]+$/D', $parts[1]), 403, 'The offline snapshot permit is invalid. Reconnect to refresh this device.');
        $signature = $this->decode($parts[1]);
        abort_unless($signature !== false && hash_equals(hash_hmac('sha256', "posonly-offline-permit-v1\0".$parts[0], $this->signingKey(), true), $signature), 403, 'The offline snapshot permit is invalid. Reconnect to refresh this device.');
        try {
            $claims = json_decode($this->decode($parts[0]) ?: '', true, 10, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(403, 'The offline snapshot permit is invalid. Reconnect to refresh this device.');
        }
        abort_unless(is_array($claims) && ($claims['version'] ?? null) === 1
            && ($claims['tenant_id'] ?? null) === $user->tenant_id && ($claims['user_id'] ?? null) === $user->id
            && is_int($claims['issued_at'] ?? null) && is_int($claims['expires_at'] ?? null)
            && (int) config('offline.cache_seconds') === $claims['expires_at'] - $claims['issued_at'], 403, 'This offline snapshot does not belong to your current workspace account.');
        $latestAllowed = now()->addSeconds((int) config('offline.clock_skew_seconds'))->timestamp;
        if ($recordedAt->timestamp < $claims['issued_at'] || $recordedAt->timestamp > $claims['expires_at'] || $recordedAt->timestamp > $latestAllowed) {
            throw ValidationException::withMessages(['recorded_at' => 'This cash sale was recorded outside the offline snapshot period. Review it before syncing.']);
        }
    }

    private function signingKey(): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Offline snapshot signing is not configured.');
        }
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded === false || $decoded === '') {
                throw new RuntimeException('Offline snapshot signing is not configured.');
            }

            return $decoded;
        }

        return $key;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
