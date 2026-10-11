<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Location;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockController extends Controller
{
    public function adjust(Request $request, InventoryService $inventory, ?int $product = null): JsonResponse
    {
        $data = $request->validate([
            'product_id' => [$product ? 'nullable' : 'required', 'integer'],
            'location_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:-100000', 'max:100000', 'not_in:0'],
            'reason' => ['required', 'string', 'max:200'],
        ]);
        $record = DB::transaction(function () use ($request, $data, $product, $inventory): Product {
            $actor = $this->authorizeActor($request);
            $record = Product::where('tenant_id', $actor->tenant_id)->findOrFail($product ?? $data['product_id']);
            $inventory->changeStock($actor, $record, $data['location_id'], $data['quantity'], 'adjustment', $data['reason']);

            return $record;
        }, 5);

        return response()->json((new ProductResource($record->load('stocks')))->resolve($request));
    }

    public function transfer(Request $request, InventoryService $inventory): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'], 'from_location_id' => ['required', 'integer'],
            'to_location_id' => ['required', 'integer', 'different:from_location_id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);
        $product = DB::transaction(function () use ($request, $data, $inventory): Product {
            $actor = $this->authorizeActor($request);
            $product = Product::where('tenant_id', $actor->tenant_id)->findOrFail($data['product_id']);
            Location::where('tenant_id', $actor->tenant_id)->findOrFail($data['to_location_id']);
            $reference = 'TR-'.(string) Str::uuid();
            $inventory->changeStock($actor, $product, $data['from_location_id'], -$data['quantity'], 'transfer_out', 'Transfer to another location', $reference);
            $inventory->changeStock($actor, $product, $data['to_location_id'], $data['quantity'], 'transfer_in', 'Transfer from another location', $reference);

            return $product;
        }, 5);

        return response()->json((new ProductResource($product->load('stocks')))->resolve($request));
    }

    private function authorizeActor(Request $request): User
    {
        Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
        $actor = User::where('tenant_id', $request->user()->tenant_id)->lockForUpdate()->findOrFail($request->user()->id);
        abort_unless($actor->is_active && in_array($actor->role, ['admin', 'manager'], true), 403, 'Manager or administrator access is required to change stock.');

        return $actor;
    }
}
