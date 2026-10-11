<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Jobs\CheckLowStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CommerceService;
use App\Services\InventoryService;
use App\Services\ProductVariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    private function validated(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'sku' => ['required', 'string', 'max:80', Rule::unique('products')->where('tenant_id', $request->user()->tenant_id)->ignore($product?->id)],
            'barcode' => ['nullable', 'string', 'max:80', Rule::unique('products')->where('tenant_id', $request->user()->tenant_id)->ignore($product?->id)],
            'category' => ['required', 'string', 'max:80'],
            'price' => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'reorder_level' => ['required', 'integer', 'min:0', 'max:100000'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'variant' => ['nullable', 'string', 'max:100'],
            'product_family_id' => ['prohibited'],
            'variant_options' => $product?->product_family_id ? ['sometimes', 'required', 'array'] : ['prohibited'],
            'location_id' => ['nullable', 'integer'], 'quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
    }

    private function attributes(array $data, ?Product $product = null): array
    {
        return [
            'name' => $data['name'], 'sku' => $data['sku'], 'barcode' => $data['barcode'] ?? null,
            'category' => $data['category'], 'price_cents' => InventoryService::cents($data['price']),
            'wholesale_price_cents' => array_key_exists('wholesale_price', $data) ? ($data['wholesale_price'] === null ? null : InventoryService::cents($data['wholesale_price'])) : $product?->wholesale_price_cents,
            'cost_cents' => InventoryService::cents($data['cost'] ?? 0),
            'reorder_level' => $data['reorder_level'], 'color' => $data['color'] ?? '#ddd9d0',
            'variant' => $data['variant'] ?? null,
        ];
    }

    public function store(Request $request, InventoryService $inventory, ProductVariantService $variants): JsonResponse
    {
        $data = $this->validated($request);
        $product = DB::transaction(function () use ($request, $data, $inventory, $variants): Product {
            $actor = $this->authorizeActor($request);
            $data = $this->validated($request);
            $variants->validateIdentifiers([['sku' => $data['sku'], 'barcode' => $data['barcode'] ?? null]], $actor->tenant_id);
            $tenantId = $actor->tenant_id;
            $locations = Location::where('tenant_id', $tenantId)->get();
            $locationId = $data['location_id'] ?? $locations->first()?->id;
            Location::where('tenant_id', $tenantId)->findOrFail($locationId);
            $product = Product::create(['tenant_id' => $tenantId] + $this->attributes($data));
            foreach ($locations as $location) {
                Stock::create(['tenant_id' => $tenantId, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 0]);
            }
            if (($data['quantity'] ?? 0) > 0) {
                $inventory->changeStock($actor, $product, $locationId, $data['quantity'], 'opening', 'Opening stock');
            }
            app(CommerceService::class)->syncInventoryForTenant($tenantId);

            return $product->load(['stocks', 'family']);
        }, 5);

        return response()->json((new ProductResource($product))->resolve($request), 201);
    }

    public function update(Request $request, int $product, ProductVariantService $variants): JsonResponse
    {
        $record = Product::where('tenant_id', $request->user()->tenant_id)->findOrFail($product);
        $data = $this->validated($request, $record);
        $record = DB::transaction(function () use ($request, $product, $data, $variants): Product {
            $actor = $this->authorizeActor($request);
            $record = Product::where('tenant_id', $actor->tenant_id)->lockForUpdate()->findOrFail($product);
            $data = $this->validated($request, $record);
            $variants->validateIdentifiers([['sku' => $data['sku'], 'barcode' => $data['barcode'] ?? null]], $actor->tenant_id, $record->id);
            $record->update(array_replace($this->attributes($data, $record), $variants->updateVariantAttributes($record, $data)));
            app(CommerceService::class)->syncInventoryForTenant($actor->tenant_id);
            CheckLowStock::dispatch($actor->tenant_id)->afterCommit();

            return $record;
        }, 5);

        return response()->json((new ProductResource($record->load(['stocks', 'family'])))->resolve($request));
    }

    private function authorizeActor(Request $request): User
    {
        $tenant = Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
        $actor = User::where('tenant_id', $request->user()->tenant_id)->lockForUpdate()->findOrFail($request->user()->id);
        abort_unless($actor->is_active && in_array($actor->role, ['admin', 'manager'], true), 403, 'Manager or administrator access is required to change products.');
        if (! app(BillingService::class)->summary($tenant)['can_write']) {
            abort(response()->json(['message' => 'Subscription access has ended. Review Billing to continue product changes.', 'code' => 'subscription_required'], 423));
        }

        return $actor;
    }
}
