<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductVariantBatch;
use App\Services\ProductVariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductFamilyController extends Controller
{
    private function batchRules(): array
    {
        return ['location_id' => ['required', 'integer'], 'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
            'variants' => ['required', 'array', 'min:1', 'max:100'], 'variants.*.options' => ['required', 'array'],
            'variants.*.sku' => ['required', 'string', 'max:80'], 'variants.*.barcode' => ['nullable', 'string', 'max:80'],
            'variants.*.quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'variants.*.price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'variants.*.cost' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'variants.*.wholesale_price' => ['nullable', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2']];
    }

    public function store(Request $request, ProductVariantService $variants): JsonResponse
    {
        $data = $request->validate($this->batchRules() + [
            'name' => ['required', 'string', 'max:80'], 'option_names' => ['required', 'array', 'min:1', 'max:2'],
            'option_names.*' => ['required', 'string', 'distinct', Rule::in(['size', 'color'])],
            'category' => ['required', 'string', 'max:80'], 'price' => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'reorder_level' => ['required', 'integer', 'min:0', 'max:100000'], 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'template_product_id' => ['prohibited'],
        ]);

        return $this->response($variants->createFamily($request->user(), $data), $request);
    }

    public function variants(Request $request, int $family, ProductVariantService $variants): JsonResponse
    {
        $data = $request->validate($this->batchRules());

        return $this->response($variants->addVariants($request->user(), $family, $data), $request);
    }

    private function response(ProductVariantBatch $batch, Request $request): JsonResponse
    {
        $family = $batch->family()->withCount('products')->firstOrFail();
        $products = Product::where('tenant_id', $batch->tenant_id)->where('product_family_id', $family->id)->whereIn('id', $batch->product_ids)->with(['stocks', 'family'])->orderBy('id')->get();

        return response()->json(['id' => $family->id, 'name' => $family->name, 'option_names' => $family->option_names,
            'default_values' => $family->defaults, 'variant_count' => $family->products_count,
            'products' => $products->map(fn (Product $product): array => (new ProductResource($product))->resolve($request))->all(),
            'created_at' => $family->created_at->toIso8601String()], 201);
    }
}
