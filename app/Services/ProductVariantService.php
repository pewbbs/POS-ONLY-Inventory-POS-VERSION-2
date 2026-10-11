<?php

namespace App\Services;

use App\Jobs\CheckLowStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductVariantBatch;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductVariantService
{
    public function __construct(private InventoryService $inventory, private CommerceService $commerce, private BillingService $billing) {}

    private function mutate(User $actor, callable $action): ProductVariantBatch
    {
        return DB::transaction(function () use ($actor, $action): ProductVariantBatch {
            $tenant = Tenant::whereKey($actor->tenant_id)->lockForUpdate()->firstOrFail();
            $user = User::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($actor->id);
            abort_unless($user->is_active && in_array($user->role, ['admin', 'manager'], true), 403, 'Manager or administrator access is required to change variants.');
            if (! $this->billing->summary($tenant)['can_write']) {
                abort(response()->json(['message' => 'Subscription access has ended. Review Billing to continue product changes.', 'code' => 'subscription_required'], 423));
            }

            return $action($user, $tenant);
        }, 5);
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function text(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private function axes(array $names): array
    {
        if ($names === [] || count($names) !== count(array_unique($names)) || array_diff($names, ['size', 'color']) !== []) {
            throw ValidationException::withMessages(['option_names' => 'Choose size, color, or both once.']);
        }

        return array_values(array_filter(['size', 'color'], fn (string $name): bool => in_array($name, $names, true)));
    }

    private function defaults(array $data): array
    {
        return ['category' => $this->text($data['category']), 'price' => InventoryService::cents($data['price']) / 100,
            'cost' => InventoryService::cents($data['cost'] ?? 0) / 100,
            'wholesale_price' => isset($data['wholesale_price']) ? InventoryService::cents($data['wholesale_price']) / 100 : null,
            'reorder_level' => (int) $data['reorder_level'], 'color' => $data['color'] ?? '#ddd9d0'];
    }

    private function options(array $options, array $axes, string $errorField): array
    {
        if (count($options) !== count($axes) || array_diff(array_keys($options), $axes) !== []) {
            throw ValidationException::withMessages([$errorField => 'Provide exactly the size and color options selected for this family.']);
        }
        $normalized = [];
        foreach ($axes as $axis) {
            if (! isset($options[$axis]) || ! is_string($options[$axis])) {
                throw ValidationException::withMessages([$errorField => 'Every selected option needs a text value.']);
            }
            $value = $this->text($options[$axis]);
            if ($value === '' || Str::length($value) > 30) {
                throw ValidationException::withMessages([$errorField => 'Option values must contain 1 to 30 characters.']);
            }
            $normalized[$axis] = $value;
        }

        return $normalized;
    }

    private function variantKey(array $options): string
    {
        return $this->hash(array_map(fn (string $value): string => Str::lower($value), $options));
    }

    private function rows(string $name, array $axes, array $defaults, array $variants): array
    {
        $rows = [];
        foreach ($variants as $index => $variant) {
            $options = $this->options($variant['options'], $axes, 'variants.'.$index.'.options');
            $label = implode(' / ', $options);
            $wholesale = array_key_exists('wholesale_price', $variant) ? $variant['wholesale_price'] : $defaults['wholesale_price'];
            $rows[] = ['name' => $name.' · '.$label, 'sku' => trim($variant['sku']),
                'barcode' => isset($variant['barcode']) && trim($variant['barcode']) !== '' ? trim($variant['barcode']) : null, 'category' => $defaults['category'],
                'price_cents' => InventoryService::cents($variant['price'] ?? $defaults['price']),
                'cost_cents' => InventoryService::cents($variant['cost'] ?? $defaults['cost']),
                'wholesale_price_cents' => $wholesale === null ? null : InventoryService::cents($wholesale),
                'reorder_level' => $defaults['reorder_level'], 'color' => $defaults['color'],
                'variant' => $label, 'variant_options' => $options, 'variant_key' => $this->variantKey($options), 'quantity' => (int) ($variant['quantity'] ?? 0)];
        }
        usort($rows, fn (array $left, array $right): int => strcmp($left['sku'], $right['sku']));

        return $rows;
    }

    private function validateBatch(array $rows, int $tenantId, ?int $familyId = null): void
    {
        $keys = [];
        $skus = [];
        $barcodes = [];
        foreach ($rows as $index => $row) {
            Validator::make($row, [
                'name' => ['required', 'string', 'max:150'],
                'sku' => ['required', 'string', 'max:80'],
                'barcode' => ['nullable', 'string', 'max:80'],
            ])->validate();
            $sku = Str::lower($row['sku']);
            $barcode = $row['barcode'] === null ? null : Str::lower($row['barcode']);
            if (isset($keys[$row['variant_key']]) || ($familyId && Product::where('tenant_id', $tenantId)->where('product_family_id', $familyId)->where('variant_key', $row['variant_key'])->exists())) {
                throw ValidationException::withMessages(['variants.'.$index.'.options' => 'This option combination already exists. Choose each size and color combination once.']);
            }
            if (isset($skus[$sku]) || ($barcode !== null && isset($barcodes[$barcode]))) {
                throw ValidationException::withMessages(['variants' => 'Each variant needs its own SKU and barcode.']);
            }
            $keys[$row['variant_key']] = true;
            $skus[$sku] = true;
            if ($barcode !== null) {
                $barcodes[$barcode] = true;
            }
        }
        $this->validateIdentifiers($rows, $tenantId);
    }

    /** The caller holds the tenant lock; SKU and barcode share the scanner namespace. */
    public function validateIdentifiers(array $rows, int $tenantId, ?int $ignoreProductId = null): void
    {
        $seen = [];
        foreach ($rows as $index => $row) {
            $identifiers = array_values(array_unique(array_filter([$row['sku'], $row['barcode'] ?? null], fn (?string $value): bool => $value !== null && $value !== '')));
            foreach ($identifiers as $identifier) {
                $key = Str::lower($identifier);
                if (isset($seen[$key]) && $seen[$key] !== $index) {
                    throw ValidationException::withMessages(['variants' => 'A SKU or barcode belongs to another row in this batch. Use a unique scanner identifier for each product.']);
                }
                $seen[$key] = $index;
            }
            $collision = Product::where('tenant_id', $tenantId)->when($ignoreProductId, fn ($query) => $query->where('id', '!=', $ignoreProductId))
                ->where(function ($query) use ($identifiers): void {
                    $query->whereIn('sku', $identifiers)->orWhereIn('barcode', $identifiers);
                })->exists();
            if ($collision) {
                throw ValidationException::withMessages(['sku' => 'This SKU or barcode already belongs to another product. Use a unique scanner identifier.']);
            }
        }
    }

    public function createFamily(User $actor, array $data): ProductVariantBatch
    {
        return $this->mutate($actor, function (User $user, Tenant $tenant) use ($data): ProductVariantBatch {
            $name = $this->text($data['name']);
            $axes = $this->axes($data['option_names']);
            $defaults = $this->defaults($data);
            $rows = $this->rows($name, $axes, $defaults, $data['variants']);
            $hash = $this->hash(['action' => 'create', 'actor_id' => $user->id, 'name' => $name, 'option_names' => $axes, 'defaults' => $defaults, 'location_id' => (int) $data['location_id'], 'rows' => $rows]);
            $existing = ProductFamily::where('tenant_id', $tenant->id)->where('creation_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->creation_hash, $hash), 409, 'This family key was already used for different product details.');

                return ProductVariantBatch::where('tenant_id', $tenant->id)->where('product_family_id', $existing->id)->where('idempotency_key', $existing->creation_key)->firstOrFail();
            }
            Location::where('tenant_id', $tenant->id)->findOrFail($data['location_id']);
            $this->validateBatch($rows, $tenant->id);
            $family = ProductFamily::create(['tenant_id' => $tenant->id, 'name' => $name, 'option_names' => $axes, 'defaults' => $defaults,
                'creation_key' => $data['idempotency_key'], 'creation_hash' => $hash]);

            return $this->persistBatch($user, $family, $rows, (int) $data['location_id'], $data['idempotency_key'], $hash);
        });
    }

    public function addVariants(User $actor, int $familyId, array $data): ProductVariantBatch
    {
        return $this->mutate($actor, function (User $user, Tenant $tenant) use ($familyId, $data): ProductVariantBatch {
            $family = ProductFamily::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($familyId);
            $rows = $this->rows($family->name, $family->option_names, $family->defaults, $data['variants']);
            $hash = $this->hash(['action' => 'append', 'actor_id' => $user->id, 'family_id' => $family->id, 'location_id' => (int) $data['location_id'], 'rows' => $rows]);
            $existing = ProductVariantBatch::where('tenant_id', $tenant->id)->where('product_family_id', $family->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This batch key was already used for different variants.');

                return $existing;
            }
            Location::where('tenant_id', $tenant->id)->findOrFail($data['location_id']);
            $this->validateBatch($rows, $tenant->id, $family->id);

            return $this->persistBatch($user, $family, $rows, (int) $data['location_id'], $data['idempotency_key'], $hash);
        });
    }

    private function persistBatch(User $actor, ProductFamily $family, array $rows, int $locationId, string $key, string $hash): ProductVariantBatch
    {
        $locations = Location::where('tenant_id', $actor->tenant_id)->orderBy('id')->get();
        $ids = [];
        foreach ($rows as $row) {
            $product = Product::create(['tenant_id' => $actor->tenant_id, 'product_family_id' => $family->id] + Arr::except($row, ['quantity']));
            $ids[] = $product->id;
            foreach ($locations as $location) {
                Stock::create(['tenant_id' => $actor->tenant_id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 0]);
            }
            if ($row['quantity'] > 0) {
                $this->inventory->changeStock($actor, $product, $locationId, $row['quantity'], 'opening', 'Opening variant stock', refreshTracking: false);
            }
        }
        $this->commerce->syncInventoryForTenant($actor->tenant_id);
        CheckLowStock::dispatch($actor->tenant_id)->afterCommit();

        return ProductVariantBatch::create(['tenant_id' => $actor->tenant_id, 'product_family_id' => $family->id, 'user_id' => $actor->id,
            'idempotency_key' => $key, 'request_hash' => $hash, 'product_ids' => $ids]);
    }

    /** The caller holds the tenant lock and the product lock. */
    public function updateVariantAttributes(Product $product, array $data): array
    {
        if (! $product->product_family_id) {
            if (array_key_exists('variant_options', $data)) {
                throw ValidationException::withMessages(['variant_options' => 'Create a product family to use structured size or color options.']);
            }

            return [];
        }
        $family = ProductFamily::where('tenant_id', $product->tenant_id)->findOrFail($product->product_family_id);
        $options = $this->options($data['variant_options'] ?? $product->variant_options, $family->option_names, 'variant_options');
        $key = $this->variantKey($options);
        if (Product::where('tenant_id', $product->tenant_id)->where('product_family_id', $family->id)->where('variant_key', $key)->where('id', '!=', $product->id)->exists()) {
            throw ValidationException::withMessages(['variant_options' => 'This size and color combination already exists in this family.']);
        }
        $label = implode(' / ', $options);

        return ['name' => $family->name.' · '.$label, 'variant' => $label, 'variant_options' => $options, 'variant_key' => $key];
    }
}
