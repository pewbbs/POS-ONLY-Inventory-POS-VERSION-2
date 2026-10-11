<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Product;
use App\Models\ReorderRule;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReplenishmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReorderRuleController extends Controller
{
    public static function serialize(ReorderRule $rule): array
    {
        $rule->loadMissing(['product:id,name,sku,reorder_level', 'location:id,name', 'supplier:id,name']);

        return [
            'id' => $rule->id, 'product_id' => $rule->product_id, 'location_id' => $rule->location_id,
            'supplier_id' => $rule->supplier_id, 'target_quantity' => $rule->target_quantity, 'enabled' => $rule->enabled,
            'product_name' => $rule->product->name, 'sku' => $rule->product->sku, 'reorder_level' => $rule->product->reorder_level,
            'location_name' => $rule->location->name, 'supplier_name' => $rule->supplier->name,
            'created_at' => $rule->created_at->toIso8601String(), 'updated_at' => $rule->updated_at->toIso8601String(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => 'nullable|integer', 'location_id' => 'nullable|integer']);
        $tenantId = $request->user()->tenant_id;
        $query = ReorderRule::where('tenant_id', $tenantId)->with(['product:id,name,sku,reorder_level', 'location:id,name', 'supplier:id,name']);
        if (isset($data['product_id'])) {
            Product::where('tenant_id', $tenantId)->findOrFail($data['product_id']);
            $query->where('product_id', $data['product_id']);
        }
        if (isset($data['location_id'])) {
            Location::where('tenant_id', $tenantId)->findOrFail($data['location_id']);
            $query->where('location_id', $data['location_id']);
        }

        return response()->json(['data' => $query->orderByDesc('id')->get()->map(self::serialize(...))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer', 'location_id' => 'required|integer', 'supplier_id' => 'required|integer',
            'target_quantity' => 'required|integer|min:1|max:1000000', 'enabled' => 'sometimes|boolean',
        ]);
        $rule = DB::transaction(function () use ($request, $data): ReorderRule {
            $tenantId = $request->user()->tenant_id;
            Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $this->authorizeActor($request);
            $product = Product::where('tenant_id', $tenantId)->findOrFail($data['product_id']);
            Location::where('tenant_id', $tenantId)->findOrFail($data['location_id']);
            Supplier::where('tenant_id', $tenantId)->findOrFail($data['supplier_id']);
            $this->validateTarget($product, $data['target_quantity'], $data['enabled'] ?? true);

            return ReorderRule::updateOrCreate(
                ['tenant_id' => $tenantId, 'product_id' => $product->id, 'location_id' => $data['location_id'], 'supplier_id' => $data['supplier_id']],
                ['user_id' => $request->user()->id, 'target_quantity' => $data['target_quantity'], 'enabled' => $data['enabled'] ?? true],
            );
        }, 5);

        return response()->json(self::serialize($rule), $rule->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, int $rule): JsonResponse
    {
        $data = $request->validate(['target_quantity' => 'sometimes|required|integer|min:1|max:1000000', 'enabled' => 'sometimes|required|boolean']);
        if ($data === []) {
            throw ValidationException::withMessages(['rule' => 'Choose a target quantity or enabled status to update.']);
        }
        $record = DB::transaction(function () use ($request, $rule, $data): ReorderRule {
            $tenantId = $request->user()->tenant_id;
            Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $this->authorizeActor($request);
            $record = ReorderRule::where('tenant_id', $tenantId)->whereKey($rule)->lockForUpdate()->firstOrFail();
            $product = Product::where('tenant_id', $tenantId)->findOrFail($record->product_id);
            $this->validateTarget($product, $data['target_quantity'] ?? $record->target_quantity, $data['enabled'] ?? $record->enabled);
            $record->update($data);

            return $record;
        }, 5);

        return response()->json(self::serialize($record));
    }

    public function generate(Request $request, ReplenishmentService $replenishment): JsonResponse
    {
        $orders = $replenishment->generateForTenant($request->user()->tenant_id, $request->user());
        $orders->load('receipts.receiver:id,name');

        return response()->json(['orders' => $orders->map(PurchaseOrderController::serialize(...)), 'created_count' => $orders->count()]);
    }

    private function validateTarget(Product $product, int $quantity, bool $enabled): void
    {
        if ($enabled && $quantity <= $product->reorder_level) {
            throw ValidationException::withMessages(['target_quantity' => 'Set the target above this product’s low-stock threshold.']);
        }
    }

    private function authorizeActor(Request $request): void
    {
        $actor = User::where('tenant_id', $request->user()->tenant_id)->lockForUpdate()->findOrFail($request->user()->id);
        abort_unless($actor->is_active && in_array($actor->role, ['admin', 'manager'], true), 403, 'Manager or administrator access is required for replenishment rules.');
    }
}
