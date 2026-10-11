<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    private function contact(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:100'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:30']]);
    }

    public function store(Request $request): JsonResponse
    {
        $customer = Customer::create(['tenant_id' => $request->user()->tenant_id] + $this->contact($request));

        return response()->json($customer->only(['id', 'name', 'email', 'phone']), 201);
    }

    public function supplier(Request $request): JsonResponse
    {
        $supplier = Supplier::create(['tenant_id' => $request->user()->tenant_id] + $this->contact($request));

        return response()->json($supplier->only(['id', 'name', 'email', 'phone']), 201);
    }

    public function location(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'address' => ['nullable', 'string', 'max:255'], 'type' => ['nullable', Rule::in(['store', 'warehouse', 'online'])]]);
        $location = DB::transaction(function () use ($request, $data): Location {
            $tenant = Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
            app(BillingService::class)->assertCapacity($tenant, 'locations');
            $location = Location::create(['tenant_id' => $request->user()->tenant_id] + $data);
            Product::where('tenant_id', $request->user()->tenant_id)->each(function (Product $product) use ($location): void {
                Stock::create(['tenant_id' => $location->tenant_id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 0]);
            });

            return $location;
        });

        return response()->json($location->only(['id', 'name', 'address', 'type']), 201);
    }
}
