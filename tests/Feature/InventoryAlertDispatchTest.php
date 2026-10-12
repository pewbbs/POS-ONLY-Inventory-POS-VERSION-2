<?php

namespace Tests\Feature;

use App\Jobs\CheckLowStock;
use App\Models\Location;
use App\Models\LowStockAlert;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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

    public function test_stock_change_during_a_running_check_keeps_a_follow_up_job_and_refreshes_the_alert(): void
    {
        $store = $this->store();
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'reorder_level' => 3]);
        $stock = Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'product_id' => $product->id,
            'location_id' => $store['location']->id, 'quantity' => 10]);
        config(['queue.default' => 'database']);
        $changed = false;
        $event = 'eloquent.retrieved: '.Stock::class;
        Event::listen($event, function (Stock $loaded) use ($store, $stock, &$changed): void {
            if ($loaded->id === $stock->id && ! $changed) {
                $changed = true;
                Stock::whereKey($stock->id)->update(['quantity' => 1]);
                CheckLowStock::dispatch($store['tenant']->id)->afterCommit();
            }
        });

        try {
            CheckLowStock::dispatch($store['tenant']->id)->afterCommit();
            $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
            $this->assertDatabaseCount('jobs', 1);
            $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 1]);
        $this->assertDatabaseHas('low_stock_alerts', ['tenant_id' => $store['tenant']->id, 'product_id' => $product->id,
            'location_id' => $store['location']->id, 'quantity' => 1, 'active' => true]);
        $this->assertDatabaseCount('low_stock_alerts', 1);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_alert_refresh_uses_settings_saved_after_the_replenishment_check_started(): void
    {
        $store = $this->store();
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'reorder_level' => 3]);
        Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'product_id' => $product->id,
            'location_id' => $store['location']->id, 'quantity' => 1]);
        $job = new CheckLowStock($store['tenant']->id);
        $job->handle();
        $changed = false;
        $event = 'eloquent.retrieved: '.Tenant::class;
        Event::listen($event, function (Tenant $loaded) use ($store, &$changed): void {
            if ($loaded->id === $store['tenant']->id && ! $changed) {
                $changed = true;
                $store['tenant']->update(['settings' => array_replace($store['tenant']->settings, ['low_stock_alerts' => false])]);
            }
        });

        try {
            $job->handle();
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseHas('low_stock_alerts', ['tenant_id' => $store['tenant']->id, 'product_id' => $product->id,
            'location_id' => $store['location']->id, 'active' => false]);
        $this->assertDatabaseCount('low_stock_alerts', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_alerts_use_available_stock_and_threshold_equality_independently_at_each_location(): void
    {
        $store = $this->store();
        $otherLocation = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'reorder_level' => 3]);
        Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'product_id' => $product->id,
            'location_id' => $store['location']->id, 'quantity' => 8, 'reserved_quantity' => 5]);
        Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'product_id' => $product->id,
            'location_id' => $otherLocation->id, 'quantity' => 8, 'reserved_quantity' => 4]);
        $job = new CheckLowStock($store['tenant']->id);

        $job->handle();
        $job->handle();

        $this->assertDatabaseHas('low_stock_alerts', ['tenant_id' => $store['tenant']->id, 'product_id' => $product->id,
            'location_id' => $store['location']->id, 'quantity' => 3, 'reorder_level' => 3, 'active' => true]);
        $this->assertDatabaseHas('low_stock_alerts', ['tenant_id' => $store['tenant']->id, 'product_id' => $product->id,
            'location_id' => $otherLocation->id, 'quantity' => 4, 'reorder_level' => 3, 'active' => false]);
        $this->assertDatabaseCount('low_stock_alerts', 2);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->actingAs($store['user'])->getJson('/api/bootstrap')->assertOk()->assertJsonCount(1, 'low_stock_alerts')
            ->assertJsonPath('low_stock_alerts.0.location_id', $store['location']->id)->assertJsonPath('low_stock_alerts.0.quantity', 3);
    }

    public function test_product_threshold_edits_activate_and_clear_alerts_without_changing_stock(): void
    {
        $store = $this->store();
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'reorder_level' => 3]);
        $stock = Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'product_id' => $product->id,
            'location_id' => $store['location']->id, 'quantity' => 5]);
        (new CheckLowStock($store['tenant']->id))->handle();
        $payload = $this->product($store);

        $this->actingAs($store['user'])->putJson('/api/products/'.$product->id, array_replace($payload, ['reorder_level' => 5]))->assertOk();
        $this->assertDatabaseHas('low_stock_alerts', ['product_id' => $product->id, 'location_id' => $store['location']->id,
            'quantity' => 5, 'reorder_level' => 5, 'active' => true]);
        $this->putJson('/api/products/'.$product->id, array_replace($payload, ['reorder_level' => 4]))->assertOk();

        $this->assertDatabaseHas('low_stock_alerts', ['product_id' => $product->id, 'location_id' => $store['location']->id,
            'quantity' => 5, 'reorder_level' => 4, 'active' => false]);
        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 5]);
        $this->assertDatabaseCount('low_stock_alerts', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_settings_disable_and_reenable_current_alerts_without_changing_another_company(): void
    {
        $store = $this->store();
        $other = $this->store();
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'reorder_level' => 3]);
        $otherProduct = Product::factory()->create(['tenant_id' => $other['tenant']->id, 'reorder_level' => 3]);
        $stock = Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'product_id' => $product->id,
            'location_id' => $store['location']->id, 'quantity' => 0]);
        Stock::factory()->create(['tenant_id' => $other['tenant']->id, 'product_id' => $otherProduct->id,
            'location_id' => $other['location']->id, 'quantity' => 0]);
        (new CheckLowStock($store['tenant']->id))->handle();
        (new CheckLowStock($other['tenant']->id))->handle();

        $this->actingAs($store['user'])->putJson('/api/settings', ['low_stock_alerts' => false])->assertOk()->assertJsonPath('low_stock_alerts', false);
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonCount(0, 'low_stock_alerts');
        $stock->update(['quantity' => 7]);
        $this->putJson('/api/settings', ['low_stock_alerts' => true])->assertOk()->assertJsonPath('low_stock_alerts', true);

        $this->assertDatabaseHas('low_stock_alerts', ['tenant_id' => $store['tenant']->id, 'product_id' => $product->id,
            'location_id' => $store['location']->id, 'quantity' => 7, 'active' => false]);
        $this->assertDatabaseHas('low_stock_alerts', ['tenant_id' => $other['tenant']->id, 'product_id' => $otherProduct->id,
            'location_id' => $other['location']->id, 'quantity' => 0, 'active' => true]);
        $this->assertDatabaseCount('low_stock_alerts', 2);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_failed_alert_scan_rolls_back_partial_alerts_and_retry_refreshes_the_whole_company(): void
    {
        $store = $this->store();
        $firstProduct = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'reorder_level' => 3]);
        $secondProduct = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'reorder_level' => 3]);
        Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'product_id' => $firstProduct->id,
            'location_id' => $store['location']->id, 'quantity' => 1]);
        Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'product_id' => $secondProduct->id,
            'location_id' => $store['location']->id, 'quantity' => 2]);
        $job = new CheckLowStock($store['tenant']->id);
        $event = 'eloquent.saving: '.LowStockAlert::class;
        Event::listen($event, function (LowStockAlert $alert) use ($secondProduct): void {
            if ($alert->product_id === $secondProduct->id) {
                throw new RuntimeException('Temporary alert storage failure.');
            }
        });

        try {
            $job->handle();
            $this->fail('The alert refresh should surface the storage failure for a job retry.');
        } catch (RuntimeException $error) {
            $this->assertSame('Temporary alert storage failure.', $error->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('low_stock_alerts', 0);
        $job->handle();

        $this->assertDatabaseHas('low_stock_alerts', ['tenant_id' => $store['tenant']->id, 'product_id' => $firstProduct->id,
            'location_id' => $store['location']->id, 'quantity' => 1, 'active' => true]);
        $this->assertDatabaseHas('low_stock_alerts', ['tenant_id' => $store['tenant']->id, 'product_id' => $secondProduct->id,
            'location_id' => $store['location']->id, 'quantity' => 2, 'active' => true]);
        $this->assertDatabaseCount('low_stock_alerts', 2);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
