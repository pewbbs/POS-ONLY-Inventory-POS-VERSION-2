<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
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
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:100'],
        ]);
        $record = DB::transaction(function () use ($request, $data, $product, $inventory): Product {
            $actor = $this->authorizeActor($request);
            $productId = (int) ($product ?? $data['product_id']);
            $reason = trim($data['reason']);
            $key = $data['idempotency_key'] ?? null;
            $requestHash = hash('sha256', json_encode([
                'user_id' => $actor->id, 'type' => 'adjustment', 'product_id' => $productId,
                'location_id' => (int) $data['location_id'], 'quantity' => (int) $data['quantity'], 'reason' => $reason,
            ], JSON_THROW_ON_ERROR));
            $alreadyApplied = $this->wasApplied($actor, $key, $requestHash);
            $record = Product::where('tenant_id', $actor->tenant_id)->findOrFail($productId);
            Location::where('tenant_id', $actor->tenant_id)->findOrFail($data['location_id']);
            if ($alreadyApplied) {
                $request->attributes->set('stock_mutation_replayed', true);

                return $record;
            }
            $movement = $inventory->changeStock($actor, $record, $data['location_id'], $data['quantity'], 'adjustment', $reason);
            if ($key !== null) {
                $movement->update(['idempotency_key' => $key, 'request_hash' => $requestHash]);
            }

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
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:100'],
        ]);
        $product = DB::transaction(function () use ($request, $data, $inventory): Product {
            $actor = $this->authorizeActor($request);
            $key = $data['idempotency_key'] ?? null;
            $requestHash = hash('sha256', json_encode([
                'user_id' => $actor->id, 'type' => 'transfer', 'product_id' => (int) $data['product_id'],
                'from_location_id' => (int) $data['from_location_id'], 'to_location_id' => (int) $data['to_location_id'],
                'quantity' => (int) $data['quantity'],
            ], JSON_THROW_ON_ERROR));
            $alreadyApplied = $this->wasApplied($actor, $key, $requestHash);
            $product = Product::where('tenant_id', $actor->tenant_id)->findOrFail($data['product_id']);
            Location::where('tenant_id', $actor->tenant_id)->findOrFail($data['from_location_id']);
            Location::where('tenant_id', $actor->tenant_id)->findOrFail($data['to_location_id']);
            if ($alreadyApplied) {
                $request->attributes->set('stock_mutation_replayed', true);

                return $product;
            }
            $reference = 'TR-'.(string) Str::uuid();
            $movement = $inventory->changeStock($actor, $product, $data['from_location_id'], -$data['quantity'], 'transfer_out', 'Transfer to another location', $reference);
            $inventory->changeStock($actor, $product, $data['to_location_id'], $data['quantity'], 'transfer_in', 'Transfer from another location', $reference);
            if ($key !== null) {
                $movement->update(['idempotency_key' => $key, 'request_hash' => $requestHash]);
            }

            return $product;
        }, 5);

        return response()->json((new ProductResource($product->load('stocks')))->resolve($request));
    }

    private function wasApplied(User $actor, ?string $key, string $requestHash): bool
    {
        if ($key === null) {
            return false;
        }
        $existing = StockMovement::where('tenant_id', $actor->tenant_id)->where('idempotency_key', $key)->first();
        if ($existing === null) {
            return false;
        }
        abort_unless($existing->request_hash !== null && hash_equals($existing->request_hash, $requestHash), 409, 'This stock change key was already used for a different request.');

        return true;
    }

    private function authorizeActor(Request $request): User
    {
        Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
        $actor = User::where('tenant_id', $request->user()->tenant_id)->lockForUpdate()->findOrFail($request->user()->id);
        abort_unless($actor->is_active && in_array($actor->role, ['admin', 'manager'], true), 403, 'Manager or administrator access is required to change stock.');

        return $actor;
    }
}
