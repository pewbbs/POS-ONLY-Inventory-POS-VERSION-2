<?php

namespace Database\Seeders;

use App\Jobs\CheckLowStock;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        if (Tenant::where('is_demo', true)->exists()) {
            return;
        }
        $tenant = Tenant::create(['name' => 'Everyday Supply Co.', 'is_demo' => true, 'settings' => Tenant::defaultSettings()]);
        $admin = User::create(['tenant_id' => $tenant->id, 'name' => 'Alex Rivera', 'email' => 'alex@posonly.test', 'password' => Str::random(40), 'role' => 'admin']);
        User::create(['tenant_id' => $tenant->id, 'name' => 'Sam Reyes', 'email' => 'sam@posonly.test', 'password' => Str::random(40), 'role' => 'manager']);
        $cashier = User::create(['tenant_id' => $tenant->id, 'name' => 'Jamie Santos', 'email' => 'jamie@posonly.test', 'password' => Str::random(40), 'role' => 'cashier']);
        $locations = collect([
            ['name' => 'Manila flagship', 'address' => 'Escolta, Manila', 'type' => 'store'],
            ['name' => 'Makati studio', 'address' => 'Poblacion, Makati', 'type' => 'store'],
            ['name' => 'Central warehouse', 'address' => 'Quezon City', 'type' => 'warehouse'],
        ])->map(fn (array $data): Location => Location::create(['tenant_id' => $tenant->id] + $data));
        $catalog = [
            ['Canvas tote', 'TOT-001', 'Accessories', 450, 210, '#d8bba0', 'Natural / One size', [72, 34, 96]],
            ['Everyday tee', 'TEE-001', 'Apparel', 590, 260, '#b5c0b6', 'Sage / Medium', [66, 28, 120]],
            ['Ceramic mug', 'MUG-001', 'Home & Living', 320, 140, '#c0b0ce', 'Lilac / 350 ml', [54, 18, 82]],
            ['Daily notebook', 'NOTE-001', 'Stationery', 180, 65, '#dfc16b', 'Mustard / A5', [58, 22, 72]],
            ['Cold brew', 'BREW-001', 'Food & Drinks', 160, 70, '#a6bda2', 'Original / 250 ml', [48, 8, 36]],
            ['Minimal cap', 'CAP-001', 'Accessories', 390, 170, '#a5b5c5', 'Blue / Adjustable', [42, 16, 52]],
            ['Linen apron', 'APRN-001', 'Home & Living', 680, 310, '#c6a18a', 'Clay / One size', [24, 5, 40]],
            ['Botanical candle', 'CNDL-001', 'Home & Living', 420, 195, '#e0bfab', 'Amber / 200 g', [32, 12, 42]],
            ['Steel water bottle', 'BOT-001', 'Accessories', 550, 240, '#8ea7a5', 'Forest / 600 ml', [36, 18, 64]],
            ['Desk organizer', 'DSK-001', 'Stationery', 290, 125, '#cabdb2', 'Sand / Small', [30, 14, 34]],
            ['Coffee beans', 'COF-001', 'Food & Drinks', 480, 225, '#b99477', 'House blend / 250 g', [34, 8, 48]],
            ['Cotton socks', 'SOCK-001', 'Apparel', 220, 85, '#dacdd2', 'Rose / Medium', [50, 20, 72]],
        ];
        $products = collect();
        foreach ($catalog as $index => [$name, $sku, $category, $price, $cost, $color, $variant, $quantities]) {
            $product = Product::create(['tenant_id' => $tenant->id, 'name' => $name, 'sku' => $sku, 'barcode' => '480100000'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT), 'category' => $category, 'price_cents' => $price * 100, 'cost_cents' => $cost * 100, 'reorder_level' => 10, 'color' => $color, 'variant' => $variant]);
            foreach ($locations as $locationIndex => $location) {
                Stock::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => $quantities[$locationIndex]]);
            }
            $products->push($product);
        }
        $customers = collect([
            ['name' => 'Mika Santos', 'email' => 'mika@example.test', 'phone' => '0917 000 1001'],
            ['name' => 'Daniel Lee', 'email' => 'daniel@example.test', 'phone' => '0917 000 1002'],
            ['name' => 'Isabel Cruz', 'email' => 'isabel@example.test', 'phone' => '0917 000 1003'],
            ['name' => 'Louise Tan', 'email' => 'louise@example.test', 'phone' => '0917 000 1004'],
        ])->map(fn (array $data): Customer => Customer::create(['tenant_id' => $tenant->id] + $data));
        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'Local Makers Collective', 'email' => 'orders@makers.example.test', 'phone' => '02 8000 1200']);
        Supplier::create(['tenant_id' => $tenant->id, 'name' => 'Daily Goods Distribution', 'email' => 'sales@daily.example.test', 'phone' => '02 8000 1300']);
        $inventory = app(InventoryService::class);
        for ($day = 6; $day >= 0; $day--) {
            for ($orderIndex = 0; $orderIndex < 3; $orderIndex++) {
                $product = $products[($day + $orderIndex) % 6];
                $sale = $inventory->sale($cashier, [
                    'location_id' => $locations[0]->id, 'customer_id' => $customers[($day + $orderIndex) % 4]->id,
                    'items' => [['product_id' => $product->id, 'quantity' => $orderIndex + 1]],
                    'discount_percent' => 0, 'tax_percent' => 12, 'payments' => [['method' => $orderIndex === 0 ? 'card' : 'cash', 'amount' => round($product->price_cents * ($orderIndex + 1) * 1.12 / 100, 2)]],
                    'idempotency_key' => 'seed-'.$day.'-'.$orderIndex,
                ]);
                $sale->update(['created_at' => now()->subDays($day)->setTime(10 + $orderIndex * 3, 15)]);
            }
        }
        $inventory->purchaseOrder($admin, ['supplier_id' => $supplier->id, 'location_id' => $locations[1]->id, 'items' => [['product_id' => $products[4]->id, 'quantity' => 24], ['product_id' => $products[6]->id, 'quantity' => 12]]]);
        (new CheckLowStock($tenant->id))->handle();
    }
}
