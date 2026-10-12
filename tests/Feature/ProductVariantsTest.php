<?php

namespace Tests\Feature;

use App\Jobs\CheckLowStock;
use App\Models\CommerceChannel;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ProductVariantService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProductVariantsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function store(string $role = 'manager'): array
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        return compact('tenant', 'actor', 'location');
    }

    private function familyData(array $store, array $changes = []): array
    {
        return array_replace(['name' => 'Everyday tee', 'option_names' => ['size', 'color'], 'category' => 'Apparel',
            'price' => 590, 'cost' => 210, 'wholesale_price' => 450, 'reorder_level' => 2, 'color' => '#d8bba0',
            'location_id' => $store['location']->id, 'idempotency_key' => 'create-tee-family',
            'variants' => [
                ['options' => ['size' => 'S', 'color' => 'Black'], 'sku' => 'TEE-S-BLACK', 'barcode' => '899000000001', 'quantity' => 5],
                ['options' => ['size' => 'M', 'color' => 'Black'], 'sku' => 'TEE-M-BLACK', 'barcode' => '899000000002', 'quantity' => 8, 'price' => 650, 'cost' => 230, 'wholesale_price' => 0],
            ]], $changes);
    }

    private function createFamily(array $store): array
    {
        return $this->actingAs($store['actor'])->postJson('/api/product-families', $this->familyData($store))->assertCreated()->json();
    }

    private function appendData(array $store, array $changes = []): array
    {
        return array_replace(['location_id' => $store['location']->id, 'idempotency_key' => 'append-tee-batch',
            'variants' => [['options' => ['size' => 'L', 'color' => 'Blue'], 'sku' => 'TEE-L-BLUE', 'barcode' => '899000000003', 'quantity' => 4]]], $changes);
    }

    private function editData(array $product, array $changes = []): array
    {
        return array_replace(['name' => $product['name'], 'sku' => $product['sku'], 'barcode' => $product['barcode'], 'category' => $product['category'],
            'price' => $product['price'], 'cost' => $product['cost'], 'reorder_level' => $product['reorder_level'], 'color' => $product['color']], $changes);
    }

    public function test_variant_endpoints_require_authentication_and_manager_access(): void
    {
        $this->postJson('/api/product-families', [])->assertUnauthorized();
        $store = $this->store('cashier');
        $family = ProductFamily::factory()->create(['tenant_id' => $store['tenant']->id]);
        $this->actingAs($store['actor'])->postJson('/api/product-families', $this->familyData($store))->assertForbidden();
        $this->postJson('/api/product-families/'.$family->id.'/variants', $this->appendData($store))->assertForbidden();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_family_creates_independent_products_and_opening_stock_without_parent_product(): void
    {
        $store = $this->store();
        $other = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        $standalone = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'sku' => 'TEE-ORIGINAL']);
        $originalStock = Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'product_id' => $standalone->id, 'location_id' => $store['location']->id, 'quantity' => 19]);
        $response = $this->actingAs($store['actor'])->postJson('/api/product-families', $this->familyData($store))->assertCreated()
            ->assertJsonPath('name', 'Everyday tee')->assertJsonPath('option_names', ['size', 'color'])->assertJsonPath('variant_count', 2)
            ->assertJsonPath('default_values.price', 590)->assertJsonPath('default_values.cost', 210)->assertJsonCount(2, 'products');
        $products = collect($response->json('products'))->keyBy('sku');
        $small = $products['TEE-S-BLACK'];
        $medium = $products['TEE-M-BLACK'];
        $this->assertSame('Everyday tee · S / Black', $small['name']);
        $this->assertSame('S / Black', $small['variant']);
        $this->assertSame(['size' => 'S', 'color' => 'Black'], $small['variant_options']);
        $this->assertSame('Everyday tee', $small['family_name']);
        $this->assertSame(590, $small['price']);
        $this->assertSame(450, $small['wholesale_price']);
        $this->assertSame(650, $medium['price']);
        $this->assertSame(230, $medium['cost']);
        $this->assertSame(0, $medium['wholesale_price']);
        $this->assertDatabaseHas('stocks', ['product_id' => $small['id'], 'location_id' => $store['location']->id, 'quantity' => 5]);
        $this->assertDatabaseHas('stocks', ['product_id' => $medium['id'], 'location_id' => $store['location']->id, 'quantity' => 8]);
        $this->assertDatabaseHas('stocks', ['product_id' => $medium['id'], 'location_id' => $other->id, 'quantity' => 0]);
        $this->assertDatabaseHas('stocks', ['id' => $originalStock->id, 'quantity' => 19]);
        $this->assertNull($standalone->refresh()->product_family_id);
        $this->assertDatabaseCount('products', 3);
        $this->assertDatabaseCount('product_families', 1);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_batch_refreshes_complete_channel_catalog_and_dispatches_one_low_stock_check(): void
    {
        $store = $this->store();
        $channel = CommerceChannel::factory()->create(['tenant_id' => $store['tenant']->id, 'location_id' => $store['location']->id, 'user_id' => $store['actor']->id]);
        Bus::fake([CheckLowStock::class]);
        $family = $this->createFamily($store);
        $this->assertDatabaseHas('channel_listings', ['commerce_channel_id' => $channel->id, 'sku' => 'TEE-S-BLACK', 'published_quantity' => 5]);
        $this->assertDatabaseHas('channel_listings', ['commerce_channel_id' => $channel->id, 'sku' => 'TEE-M-BLACK', 'published_quantity' => 8]);
        $this->assertSame(2, count($family['products']));
        Bus::assertDispatchedTimes(CheckLowStock::class, 1);
    }

    public function test_creation_key_replays_original_batch_after_append_and_rejects_changed_payload_or_actor(): void
    {
        $store = $this->store();
        $data = $this->familyData($store);
        $first = $this->actingAs($store['actor'])->postJson('/api/product-families', $data)->assertCreated()->json();
        $this->postJson('/api/product-families/'.$first['id'].'/variants', $this->appendData($store))->assertCreated();
        $data['variants'] = array_reverse($data['variants']);
        $replay = $this->postJson('/api/product-families', $data)->assertCreated()->assertJsonPath('id', $first['id'])->assertJsonPath('variant_count', 3)->assertJsonCount(2, 'products');
        $this->assertSame(array_column($first['products'], 'id'), array_column($replay->json('products'), 'id'));
        $this->postJson('/api/product-families', array_replace($data, ['price' => 591]))->assertConflict();
        $another = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'admin']);
        $this->actingAs($another)->postJson('/api/product-families', $data)->assertConflict();
        $this->assertDatabaseCount('products', 3);
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertDatabaseCount('product_variant_batches', 2);
    }

    public function test_append_uses_family_defaults_and_preserves_original_stock_on_retries(): void
    {
        $store = $this->store();
        $family = $this->createFamily($store);
        $data = $this->appendData($store);
        $first = $this->postJson('/api/product-families/'.$family['id'].'/variants', $data)->assertCreated()
            ->assertJsonPath('products.0.price', 590)->assertJsonPath('products.0.cost', 210)->assertJsonPath('products.0.wholesale_price', 450)->json();
        $this->postJson('/api/product-families/'.$family['id'].'/variants', $data)->assertCreated()->assertJsonPath('products.0.id', $first['products'][0]['id']);
        $data['variants'][0]['quantity'] = 9;
        $this->postJson('/api/product-families/'.$family['id'].'/variants', $data)->assertConflict();
        $this->assertDatabaseHas('stocks', ['product_id' => $first['products'][0]['id'], 'location_id' => $store['location']->id, 'quantity' => 4]);
        $this->assertDatabaseHas('stocks', ['product_id' => $family['products'][0]['id'], 'location_id' => $store['location']->id, 'quantity' => 8]);
        $this->assertDatabaseCount('products', 3);
    }

    #[TestWith([['color']])]
    #[TestWith([['size']])]
    public function test_one_axis_families_are_supported_with_canonical_labels(array $axes): void
    {
        $store = $this->store();
        $value = $axes[0] === 'size' ? 'XL' : 'Navy';
        $data = $this->familyData($store, ['option_names' => $axes, 'variants' => [['options' => [$axes[0] => $value], 'sku' => 'ONE-OPTION', 'barcode' => '0']]]);
        $this->actingAs($store['actor'])->postJson('/api/product-families', $data)->assertCreated()
            ->assertJsonPath('option_names', $axes)->assertJsonPath('products.0.variant', $value)->assertJsonPath('products.0.barcode', '0')
            ->assertJsonPath('products.0.stocks.0.quantity', 0);
    }

    public function test_duplicate_normalized_options_roll_back_the_entire_family(): void
    {
        $store = $this->store();
        $data = $this->familyData($store);
        $data['variants'][1]['options'] = ['color' => ' black ', 'size' => 's'];
        $this->actingAs($store['actor'])->postJson('/api/product-families', $data)->assertUnprocessable();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_families', 0);
        $this->assertDatabaseCount('stocks', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_append_rejects_existing_combination_and_invalid_axes_without_partial_products(): void
    {
        $store = $this->store();
        $family = $this->createFamily($store);
        $data = $this->appendData($store);
        $data['variants'][] = ['options' => ['size' => 's', 'color' => 'BLACK'], 'sku' => 'DUPLICATE-COMBO'];
        $this->postJson('/api/product-families/'.$family['id'].'/variants', $data)->assertUnprocessable();
        $data['variants'][1]['options'] = ['size' => 'XXL', 'material' => 'Cotton'];
        $this->postJson('/api/product-families/'.$family['id'].'/variants', $data)->assertUnprocessable();
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('product_variant_batches', 1);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    #[TestWith(['sku'])]
    #[TestWith(['barcode'])]
    public function test_batch_duplicate_scanner_identifiers_and_late_catalog_collisions_leave_no_family(string $field): void
    {
        $store = $this->store();
        $data = $this->familyData($store);
        $data['variants'][1][$field] = $data['variants'][0][$field];
        $this->actingAs($store['actor'])->postJson('/api/product-families', $data)->assertUnprocessable();
        $existing = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'sku' => 'TAKEN-SKU', 'barcode' => 'TAKEN-CODE']);
        $data = $this->familyData($store);
        $data['variants'][1][$field] = $existing->$field;
        $this->postJson('/api/product-families', $data)->assertUnprocessable();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_families', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_cross_field_scanner_collisions_are_rejected_in_batches_and_normal_product_writes(): void
    {
        $store = $this->store();
        $existing = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'sku' => 'EXISTING-SKU', 'barcode' => 'EXISTING-CODE']);
        $data = $this->familyData($store);
        $data['variants'][1]['sku'] = $existing->barcode;
        $this->actingAs($store['actor'])->postJson('/api/product-families', $data)->assertUnprocessable();
        $data = $this->familyData($store);
        $data['variants'][1]['barcode'] = $data['variants'][0]['sku'];
        $this->postJson('/api/product-families', $data)->assertUnprocessable();
        $normal = ['name' => 'New item', 'sku' => 'UNIQUE-SKU', 'barcode' => $existing->sku, 'category' => 'General', 'price' => 10, 'reorder_level' => 1, 'location_id' => $store['location']->id];
        $this->postJson('/api/products', $normal)->assertUnprocessable();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_families', 0);
    }

    #[TestWith(['product_create', 'sku', 'sku'])]
    #[TestWith(['product_create', 'barcode', 'sku'])]
    #[TestWith(['product_create', 'sku', 'barcode'])]
    #[TestWith(['product_create', 'barcode', 'barcode'])]
    #[TestWith(['product_update', 'barcode', 'sku'])]
    #[TestWith(['family_create', 'barcode', 'sku'])]
    #[TestWith(['family_append', 'sku', 'barcode'])]
    public function test_scanner_identifier_case_collisions_are_422_without_stock_changes(string $operation, string $incomingField, string $existingField): void
    {
        $store = $this->store();
        $existing = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'sku' => 'EXISTING-SKU', 'barcode' => 'EXISTING-CODE']);
        $originalStock = Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'product_id' => $existing->id, 'location_id' => $store['location']->id, 'quantity' => 9]);
        $identifier = strtolower($existing->{$existingField});
        $normal = ['name' => 'New item', 'sku' => 'UNIQUE-SKU', 'barcode' => 'UNIQUE-CODE', 'category' => 'General', 'price' => 10,
            'reorder_level' => 1, 'location_id' => $store['location']->id, 'quantity' => 4, $incomingField => $identifier];
        $this->actingAs($store['actor']);

        if ($operation === 'product_update') {
            $editable = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'sku' => 'EDITABLE-SKU', 'barcode' => 'EDITABLE-CODE']);
            Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'product_id' => $editable->id, 'location_id' => $store['location']->id, 'quantity' => 7]);
            $response = $this->putJson('/api/products/'.$editable->id, $normal);
        } elseif ($operation === 'family_create') {
            $data = $this->familyData($store);
            $data['variants'][0][$incomingField] = $identifier;
            $response = $this->postJson('/api/product-families', $data);
        } elseif ($operation === 'family_append') {
            $family = $this->createFamily($store);
            $data = $this->appendData($store);
            $data['variants'][0][$incomingField] = $identifier;
            $response = $this->postJson('/api/product-families/'.$family['id'].'/variants', $data);
        } else {
            $response = $this->postJson('/api/products', $normal);
        }

        $response->assertUnprocessable()->assertJsonValidationErrors('sku');
        if ($operation === 'product_update') {
            $this->assertDatabaseHas('products', ['id' => $editable->id, 'sku' => 'EDITABLE-SKU', 'barcode' => 'EDITABLE-CODE']);
            $this->assertDatabaseHas('stocks', ['product_id' => $editable->id, 'location_id' => $store['location']->id, 'quantity' => 7]);
        }
        $this->assertDatabaseHas('products', ['id' => $existing->id, 'sku' => 'EXISTING-SKU', 'barcode' => 'EXISTING-CODE']);
        $this->assertDatabaseHas('stocks', ['id' => $originalStock->id, 'quantity' => 9]);
        $this->assertDatabaseCount('products', $operation === 'family_append' ? 3 : ($operation === 'product_update' ? 2 : 1));
        $this->assertDatabaseCount('stocks', $operation === 'family_append' ? 3 : ($operation === 'product_update' ? 2 : 1));
        $this->assertDatabaseCount('product_families', $operation === 'family_append' ? 1 : 0);
        $this->assertDatabaseCount('product_variant_batches', $operation === 'family_append' ? 1 : 0);
        $this->assertDatabaseCount('stock_movements', $operation === 'family_append' ? 2 : 0);
    }

    #[TestWith(['Ñ-CODE', 'Ñ-CODE'])]
    #[TestWith(['Ñ-CODE', 'ñ-code'])]
    #[TestWith(['É-CODE', 'é-code'])]
    #[TestWith(['K-CODE', 'k-code'])]
    #[TestWith(['İ-CODE', 'i̇-code'])]
    public function test_unicode_scanner_identifier_collisions_are_422_without_catalog_or_stock_changes(string $existingSku, string $incomingBarcode): void
    {
        $store = $this->store();
        $existing = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'sku' => $existingSku, 'barcode' => 'EXISTING-CODE']);
        $stock = Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'product_id' => $existing->id, 'location_id' => $store['location']->id, 'quantity' => 9]);
        $data = ['name' => 'New item', 'sku' => 'NEW-SKU', 'barcode' => $incomingBarcode, 'category' => 'General', 'price' => 10,
            'reorder_level' => 1, 'location_id' => $store['location']->id, 'quantity' => 4];

        $this->actingAs($store['actor'])->postJson('/api/products', $data)->assertUnprocessable()->assertJsonValidationErrors('sku');

        $this->assertDatabaseHas('products', ['id' => $existing->id, 'sku' => $existingSku, 'barcode' => 'EXISTING-CODE']);
        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 9]);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('stocks', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    #[TestWith(['OWN-SKU', 'own-sku', 'OWN-CODE', 'own-code'])]
    #[TestWith(['Ñ-SKU', 'ñ-sku', 'É-CODE', 'é-code'])]
    public function test_scanner_identifier_case_updates_ignore_own_product_and_other_tenants(string $originalSku, string $requestedSku, string $originalBarcode, string $requestedBarcode): void
    {
        $store = $this->store();
        $foreign = $this->store();
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'sku' => $originalSku, 'barcode' => $originalBarcode]);
        $foreignProduct = Product::factory()->create(['tenant_id' => $foreign['tenant']->id, 'sku' => $requestedSku, 'barcode' => $requestedBarcode]);
        $stock = Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'product_id' => $product->id, 'location_id' => $store['location']->id, 'quantity' => 7]);
        $foreignStock = Stock::factory()->create(['tenant_id' => $foreign['tenant']->id, 'product_id' => $foreignProduct->id, 'location_id' => $foreign['location']->id, 'quantity' => 11]);
        $data = ['name' => $product->name, 'sku' => $requestedSku, 'barcode' => $requestedBarcode, 'category' => $product->category, 'price' => 10, 'reorder_level' => 1];

        $this->actingAs($store['actor'])->putJson('/api/products/'.$product->id, $data)->assertOk()->assertJsonPath('sku', $requestedSku)->assertJsonPath('barcode', $requestedBarcode);

        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 7]);
        $this->assertDatabaseHas('stocks', ['id' => $foreignStock->id, 'quantity' => 11]);
        $this->assertDatabaseHas('products', ['id' => $foreignProduct->id, 'sku' => $requestedSku, 'barcode' => $requestedBarcode]);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_unique_unicode_scanner_identifiers_are_saved_without_rewriting(): void
    {
        $store = $this->store();
        $existing = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'sku' => 'EXISTING-SKU', 'barcode' => 'EXISTING-CODE']);
        $data = ['name' => 'Imported product', 'sku' => 'CAFÉ-S', 'barcode' => '商品-001', 'category' => 'General', 'price' => 10,
            'reorder_level' => 1, 'location_id' => $store['location']->id, 'quantity' => 4];

        $response = $this->actingAs($store['actor'])->postJson('/api/products', $data)->assertCreated()->assertJsonPath('sku', 'CAFÉ-S')->assertJsonPath('barcode', '商品-001');

        $this->assertDatabaseHas('products', ['id' => $response->json('id'), 'sku' => 'CAFÉ-S', 'barcode' => '商品-001']);
        $this->assertDatabaseHas('products', ['id' => $existing->id, 'sku' => 'EXISTING-SKU', 'barcode' => 'EXISTING-CODE']);
        $this->assertDatabaseHas('stocks', ['product_id' => $response->json('id'), 'location_id' => $store['location']->id, 'quantity' => 4]);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_variant_edit_preserves_family_identity_and_regenerates_name_from_options(): void
    {
        $store = $this->store();
        $family = $this->createFamily($store);
        $small = collect($family['products'])->firstWhere('sku', 'TEE-S-BLACK');
        $this->putJson('/api/products/'.$small['id'], $this->editData($small, ['name' => 'Unrelated label', 'variant_options' => ['size' => 'L', 'color' => ' Green '], 'price' => 699]))->assertOk()
            ->assertJsonPath('name', 'Everyday tee · L / Green')->assertJsonPath('variant', 'L / Green')->assertJsonPath('product_family_id', $family['id'])->assertJsonPath('price', 699);
        $updated = $this->getJson('/api/bootstrap')->json('products');
        $current = collect($updated)->firstWhere('id', $small['id']);
        $this->putJson('/api/products/'.$small['id'], $this->editData($current, ['name' => 'Old client label']))->assertOk()->assertJsonPath('variant_options', ['size' => 'L', 'color' => 'Green'])->assertJsonPath('name', 'Everyday tee · L / Green');
        $this->assertDatabaseHas('stocks', ['product_id' => $small['id'], 'location_id' => $store['location']->id, 'quantity' => 5]);
        $this->assertDatabaseHas('product_families', ['id' => $family['id'], 'name' => 'Everyday tee']);
    }

    public function test_variant_edit_rejects_duplicate_options_membership_changes_and_cross_field_identifiers(): void
    {
        $store = $this->store();
        $family = $this->createFamily($store);
        $small = collect($family['products'])->firstWhere('sku', 'TEE-S-BLACK');
        $medium = collect($family['products'])->firstWhere('sku', 'TEE-M-BLACK');
        $this->putJson('/api/products/'.$small['id'], $this->editData($small, ['variant_options' => ['size' => 'm', 'color' => 'BLACK']]))->assertUnprocessable()->assertJsonValidationErrors('variant_options');
        $this->putJson('/api/products/'.$small['id'], $this->editData($small, ['product_family_id' => 999]))->assertUnprocessable()->assertJsonValidationErrors('product_family_id');
        $this->putJson('/api/products/'.$small['id'], $this->editData($small, ['sku' => $medium['barcode']]))->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $small['id'], 'name' => 'Everyday tee · S / Black', 'sku' => 'TEE-S-BLACK']);
    }

    public function test_standalone_products_cannot_acquire_structured_options_through_normal_edit(): void
    {
        $store = $this->store();
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $data = ['name' => $product->name, 'sku' => $product->sku, 'category' => $product->category, 'price' => 450, 'reorder_level' => 10, 'variant_options' => ['size' => 'S']];
        $this->actingAs($store['actor'])->putJson('/api/products/'.$product->id, $data)->assertUnprocessable()->assertJsonValidationErrors('variant_options');
        $this->assertNull($product->refresh()->product_family_id);
    }

    public function test_foreign_family_and_opening_location_are_hidden_without_mutating_stock(): void
    {
        $store = $this->store();
        $foreign = $this->store();
        $family = $this->createFamily($foreign);
        $this->actingAs($store['actor'])->postJson('/api/product-families/'.$family['id'].'/variants', $this->appendData($store))->assertNotFound();
        $this->postJson('/api/product-families', $this->familyData($store, ['location_id' => $foreign['location']->id]))->assertNotFound();
        $this->assertDatabaseCount('product_families', 1);
        $this->assertDatabaseCount('products', 2);
    }

    public function test_expired_subscription_blocks_family_append_and_direct_service_writes(): void
    {
        $store = $this->store();
        $family = $this->createFamily($store);
        Subscription::where('tenant_id', $store['tenant']->id)->update(['trial_ends_at' => now()->subDays(8)]);
        $this->postJson('/api/product-families', $this->familyData($store, ['idempotency_key' => 'expired-new-family']))->assertStatus(423);
        $this->postJson('/api/product-families/'.$family['id'].'/variants', $this->appendData($store))->assertStatus(423);
        try {
            app(ProductVariantService::class)->addVariants($store['actor'], $family['id'], $this->appendData($store));
            $this->fail('Expired subscriptions must not create variants through a stale request.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(423, $exception->getResponse()->getStatusCode());
        }
        $this->assertDatabaseCount('products', 2);
    }

    #[TestWith(['cashier', true])]
    #[TestWith(['manager', false])]
    public function test_service_rechecks_role_and_active_state_under_lock(string $role, bool $isActive): void
    {
        $store = $this->store();
        User::whereKey($store['actor']->id)->update(['role' => $role, 'is_active' => $isActive]);
        try {
            app(ProductVariantService::class)->createFamily($store['actor'], $this->familyData($store));
            $this->fail('Inactive or unprivileged actors must not create a family.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('product_families', 0);
        $this->assertDatabaseCount('products', 0);
    }
}
