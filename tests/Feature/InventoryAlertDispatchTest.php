<?php

namespace Tests\Feature;

use App\Jobs\CheckLowStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryAlertDispatchTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function store(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        return compact('tenant', 'user', 'location');
    }

    private function product(array $store): array
    {
        return ['name' => 'New product', 'sku' => 'NEW-PRODUCT', 'category' => 'General', 'price' => 10, 'cost' => 5,
            'reorder_level' => 3, 'location_id' => $store['location']->id];
    }

    public static function openingStock(): array
    {
        return ['omitted' => [[], 0], 'zero' => [['quantity' => 0], 0], 'positive' => [['quantity' => 5], 5]];
    }

    #[DataProvider('openingStock')]
    public function test_new_product_queues_one_post_commit_stock_check_with_any_opening_quantity(array $changes, int $quantity): void
    {
        $store = $this->store();
        Queue::fake([CheckLowStock::class]);

        $response = $this->actingAs($store['user'])->postJson('/api/products', array_replace($this->product($store), $changes))
            ->assertCreated()->assertJsonPath('stocks.0.quantity', $quantity);

        $this->assertDatabaseHas('stocks', ['product_id' => $response->json('id'), 'location_id' => $store['location']->id, 'quantity' => $quantity]);
        Queue::assertPushed(CheckLowStock::class, fn (CheckLowStock $job): bool => $job->tenantId === $store['tenant']->id && $job->afterCommit === true);
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    public function test_zero_opening_stock_is_included_in_persisted_low_stock_alerts(): void
    {
        $store = $this->store();

        $response = $this->actingAs($store['user'])->postJson('/api/products', $this->product($store))->assertCreated();

        $this->assertDatabaseHas('low_stock_alerts', ['tenant_id' => $store['tenant']->id, 'product_id' => $response->json('id'),
            'location_id' => $store['location']->id, 'quantity' => 0, 'reorder_level' => 3, 'active' => true]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_invalid_new_product_does_not_queue_stock_check(): void
    {
        $store = $this->store();
        $otherLocation = Location::factory()->create();
        Queue::fake([CheckLowStock::class]);

        $this->actingAs($store['user'])->postJson('/api/products', array_replace($this->product($store), ['location_id' => $otherLocation->id]))->assertNotFound();

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('stocks', 0);
        Queue::assertNothingPushed();
    }

    public function test_new_location_queues_post_commit_stock_check_for_zero_stock_rows(): void
    {
        $store = $this->store();
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        Queue::fake([CheckLowStock::class]);

        $response = $this->actingAs($store['user'])->postJson('/api/locations', ['name' => 'New warehouse', 'type' => 'warehouse'])->assertCreated();

        $this->assertDatabaseHas('stocks', ['tenant_id' => $store['tenant']->id, 'product_id' => $product->id, 'location_id' => $response->json('id'), 'quantity' => 0]);
        Queue::assertPushed(CheckLowStock::class, fn (CheckLowStock $job): bool => $job->tenantId === $store['tenant']->id && $job->afterCommit === true);
    }

    public function test_new_location_zero_stock_alert_does_not_change_existing_location_stock(): void
    {
        $store = $this->store();
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'reorder_level' => 3]);
        $stock = Stock::create(['tenant_id' => $store['tenant']->id, 'product_id' => $product->id, 'location_id' => $store['location']->id, 'quantity' => 10]);

        $response = $this->actingAs($store['user'])->postJson('/api/locations', ['name' => 'New warehouse', 'type' => 'warehouse'])->assertCreated();

        $this->assertDatabaseHas('low_stock_alerts', ['tenant_id' => $store['tenant']->id, 'product_id' => $product->id,
            'location_id' => $response->json('id'), 'quantity' => 0, 'active' => true]);
        $this->assertDatabaseHas('low_stock_alerts', ['product_id' => $product->id, 'location_id' => $store['location']->id, 'quantity' => 10, 'active' => false]);
        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 10]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_disabled_alerts_remain_disabled_after_zero_stock_product_creation(): void
    {
        $store = $this->store();
        $store['tenant']->update(['settings' => array_replace($store['tenant']->settings, ['low_stock_alerts' => false])]);

        $response = $this->actingAs($store['user'])->postJson('/api/products', $this->product($store))->assertCreated();

        $this->assertDatabaseHas('products', ['id' => $response->json('id')]);
        $this->assertDatabaseCount('low_stock_alerts', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_location_limit_failure_does_not_queue_stock_check_or_create_stock_rows(): void
    {
        $store = $this->store();
        $store['tenant']->update(['settings' => Tenant::defaultSettings('starter')]);
        Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        Queue::fake([CheckLowStock::class]);

        $this->actingAs($store['user'])->postJson('/api/locations', ['name' => 'Exceeds plan limit', 'type' => 'warehouse'])->assertUnprocessable();

        $this->assertDatabaseCount('locations', 1);
        $this->assertDatabaseCount('stocks', 0);
        Queue::assertNothingPushed();
    }
}
