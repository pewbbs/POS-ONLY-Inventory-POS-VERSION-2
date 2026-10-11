<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductFamilyWorkspaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function catalog(): array
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $payload = [
            'name' => 'Work tee', 'option_names' => ['size', 'color'], 'category' => 'Apparel',
            'price' => 500, 'wholesale_price' => 400, 'cost' => 220, 'reorder_level' => 2,
            'color' => '#343434', 'location_id' => $location->id, 'idempotency_key' => 'workspace-family-created',
            'variants' => [
                ['options' => ['size' => 'S', 'color' => 'Black'], 'sku' => 'WORK-S-BLK', 'barcode' => '730001', 'quantity' => 8],
                ['options' => ['size' => 'M', 'color' => 'Black'], 'sku' => 'WORK-M-BLK', 'barcode' => '730002', 'quantity' => 5, 'price' => 550],
            ],
        ];

        return compact('tenant', 'actor', 'location', 'payload');
    }

    public function test_bootstrap_groups_variants_without_exposing_another_tenant_catalog(): void
    {
        $catalog = $this->catalog();
        $created = $this->actingAs($catalog['actor'])->postJson('/api/product-families', $catalog['payload'])->assertCreated()->json();
        $foreign = Product::factory()->create(['sku' => 'FOREIGN-VARIANT', 'name' => 'Foreign catalog item']);

        $response = $this->getJson('/api/bootstrap')->assertOk()
            ->assertJsonCount(1, 'product_families')->assertJsonPath('product_families.0.id', $created['id'])
            ->assertJsonPath('product_families.0.name', 'Work tee')->assertJsonPath('product_families.0.option_names', ['size', 'color'])
            ->assertJsonPath('product_families.0.default_values.price', 500)->assertJsonPath('product_families.0.default_values.cost', 220)
            ->assertJsonPath('product_families.0.variant_count', 2)->assertJsonCount(2, 'products')
            ->assertJsonPath('products.0.product_family_id', $created['id'])->assertJsonPath('products.0.family_name', 'Work tee')
            ->assertJsonPath('stats.stock_units', 13)->assertJsonPath('stats.available_units', 13);

        $small = collect($response->json('products'))->firstWhere('sku', 'WORK-S-BLK');
        $medium = collect($response->json('products'))->firstWhere('sku', 'WORK-M-BLK');
        $this->assertSame(['size' => 'S', 'color' => 'Black'], $small['variant_options']);
        $this->assertSame('730001', $small['barcode']);
        $this->assertSame(550, $medium['price']);
        $this->assertNotContains($foreign->id, array_column($response->json('products'), 'id'));
    }

    public function test_cashier_can_read_variant_identifiers_and_stock_without_family_cost_defaults(): void
    {
        $catalog = $this->catalog();
        $this->actingAs($catalog['actor'])->postJson('/api/product-families', $catalog['payload'])->assertCreated();
        $cashier = User::factory()->create(['tenant_id' => $catalog['tenant']->id, 'role' => 'cashier']);

        $response = $this->actingAs($cashier)->getJson('/api/bootstrap')->assertOk()
            ->assertJsonPath('product_families', [])->assertJsonCount(2, 'products')
            ->assertJsonPath('products.0.family_name', 'Work tee')->assertJsonPath('products.0.option_names', ['size', 'color'])
            ->assertJsonPath('products.0.cost', 0)->assertJsonPath('products.1.cost', 0);

        $small = collect($response->json('products'))->firstWhere('sku', 'WORK-S-BLK');
        $this->assertSame(['size' => 'S', 'color' => 'Black'], $small['variant_options']);
        $this->assertSame(8, $small['stocks'][0]['available_quantity']);
        $this->assertArrayNotHasKey('default_values', $response->json('products.0'));
    }

    public function test_family_creation_and_append_are_audited_by_family_id_and_failed_batches_are_not(): void
    {
        $catalog = $this->catalog();
        $family = $this->actingAs($catalog['actor'])->postJson('/api/product-families', $catalog['payload'])->assertCreated()->json();
        $append = ['location_id' => $catalog['location']->id, 'idempotency_key' => 'workspace-family-appended',
            'variants' => [['options' => ['size' => 'L', 'color' => 'Black'], 'sku' => 'WORK-L-BLK', 'quantity' => 3]]];
        $this->postJson('/api/product-families/'.$family['id'].'/variants', $append)->assertCreated();
        $append['idempotency_key'] = 'workspace-family-invalid';
        $this->postJson('/api/product-families/'.$family['id'].'/variants', $append)->assertUnprocessable();

        $this->assertDatabaseHas('activity_logs', ['tenant_id' => $catalog['tenant']->id, 'actor_id' => $catalog['actor']->id,
            'action' => 'product_family.created', 'resource_type' => 'product_family', 'resource_id' => $family['id']]);
        $this->getJson('/api/activity?action=product_family.variants_added')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.resource_id', $family['id']);
        $this->assertDatabaseCount('activity_logs', 2);
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('product_families.0.variant_count', 3)->assertJsonPath('stats.stock_units', 16);
    }
}
