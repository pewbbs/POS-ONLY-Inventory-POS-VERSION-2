<?php

namespace Tests\Feature;

use App\Jobs\CheckLowStock;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CommerceService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StockMutationRetryTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** @return array{tenant: Tenant, user: User, location: Location, product: Product, stock: Stock} */
    private function store(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'manager']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        $stock = Stock::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 10]);

        return compact('tenant', 'user', 'location', 'product', 'stock');
    }

    private function adjustment(array $store, array $changes = []): array
    {
        return array_replace(['product_id' => $store['product']->id, 'location_id' => $store['location']->id,
            'quantity' => 3, 'reason' => 'Recount', 'idempotency_key' => 'adjustment-retry-001'], $changes);
    }

    private function transfer(array $store, Location $destination, array $changes = []): array
    {
        return array_replace(['product_id' => $store['product']->id, 'from_location_id' => $store['location']->id,
            'to_location_id' => $destination->id, 'quantity' => 3, 'idempotency_key' => 'transfer-retry-001'], $changes);
    }

    public function test_adjustment_alias_retry_changes_stock_and_records_activity_and_stock_check_only_once(): void
    {
        $store = $this->store();
        $data = $this->adjustment($store);
        Queue::fake([CheckLowStock::class]);

        $this->actingAs($store['user'])->postJson('/api/stock/adjust', $data)->assertOk();
        $retry = $data;
        unset($retry['product_id']);
        $retry['quantity'] = '3';
        $retry['location_id'] = (string) $store['location']->id;
        $retry['reason'] = '  Recount  ';
        $this->postJson('/api/products/'.$store['product']->id.'/adjust', $retry)->assertOk()->assertJsonPath('stocks.0.quantity', 13);

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 13]);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', ['tenant_id' => $store['tenant']->id, 'product_id' => $store['product']->id,
            'location_id' => $store['location']->id, 'user_id' => $store['user']->id, 'type' => 'adjustment', 'quantity' => 3, 'reason' => 'Recount']);
        $this->assertSame(1, ActivityLog::where('action', 'inventory.adjusted')->count());
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    public function test_transfer_alias_retry_preserves_both_locations_and_one_paired_movement_reference(): void
    {
        $store = $this->store();
        $destination = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        $destinationStock = Stock::create(['tenant_id' => $store['tenant']->id, 'product_id' => $store['product']->id, 'location_id' => $destination->id, 'quantity' => 4]);
        $data = $this->transfer($store, $destination);
        Queue::fake([CheckLowStock::class]);

        $this->actingAs($store['user'])->postJson('/api/stock/transfer', $data)->assertOk();
        $checks = Queue::pushed(CheckLowStock::class)->count();
        $this->postJson('/api/transfers', array_map('strval', $data))->assertOk()
            ->assertJsonFragment(['location_id' => $store['location']->id, 'quantity' => 7, 'on_hand' => 7, 'reserved_quantity' => 0, 'available_quantity' => 7])
            ->assertJsonFragment(['location_id' => $destination->id, 'quantity' => 7, 'on_hand' => 7, 'reserved_quantity' => 0, 'available_quantity' => 7]);

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 7]);
        $this->assertDatabaseHas('stocks', ['id' => $destinationStock->id, 'quantity' => 7]);
        $this->assertDatabaseCount('stock_movements', 2);
        $outbound = StockMovement::where('type', 'transfer_out')->sole();
        $this->assertNotNull($outbound->reference);
        $this->assertDatabaseHas('stock_movements', ['type' => 'transfer_in', 'reference' => $outbound->reference, 'quantity' => 3, 'location_id' => $destination->id]);
        $this->assertSame(1, ActivityLog::where('action', 'inventory.transferred')->count());
        Queue::assertPushed(CheckLowStock::class, $checks);
    }

    public function test_retry_returns_current_stock_and_channel_quantity_after_a_later_adjustment(): void
    {
        $store = $this->store();
        $channel = app(CommerceService::class)->createChannel($store['user'], ['name' => 'Test channel', 'kind' => 'web_store',
            'location_id' => $store['location']->id, 'idempotency_key' => 'test-stock-channel']);
        $data = $this->adjustment($store);
        Queue::fake([CheckLowStock::class]);

        $this->actingAs($store['user'])->postJson('/api/stock/adjust', $data)->assertOk();
        $this->postJson('/api/stock/adjust', $this->adjustment($store, ['quantity' => -1, 'reason' => 'Later count', 'idempotency_key' => 'later-adjustment-001']))->assertOk();
        $this->postJson('/api/stock/adjust', $data)->assertOk()->assertJsonPath('stocks.0.quantity', 12);

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 12]);
        $this->assertDatabaseHas('channel_listings', ['commerce_channel_id' => $channel->id, 'product_id' => $store['product']->id, 'published_quantity' => 12]);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('channel_syncs', 0);
        $this->assertSame(2, ActivityLog::where('action', 'inventory.adjusted')->count());
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    public static function alteredAdjustments(): array
    {
        return ['quantity' => [['quantity' => 4]], 'reason' => [['reason' => 'Damaged goods']]];
    }

    #[DataProvider('alteredAdjustments')]
    public function test_altered_adjustment_retry_returns_409_without_another_stock_change(array $changes): void
    {
        $store = $this->store();
        $data = $this->adjustment($store);
        $this->actingAs($store['user'])->postJson('/api/stock/adjust', $data)->assertOk();

        $this->postJson('/api/stock/adjust', array_replace($data, $changes))->assertConflict()
            ->assertJsonPath('message', 'This stock change key was already used for a different request.');

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 13]);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(1, ActivityLog::where('action', 'inventory.adjusted')->count());
    }

    public function test_adjustment_key_reuse_for_another_product_returns_409(): void
    {
        $store = $this->store();
        $otherProduct = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $data = $this->adjustment($store);
        $this->actingAs($store['user'])->postJson('/api/stock/adjust', $data)->assertOk();

        $this->postJson('/api/products/'.$otherProduct->id.'/adjust', $data)->assertConflict();

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 13]);
        $this->assertDatabaseMissing('stocks', ['product_id' => $otherProduct->id]);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_adjustment_key_reuse_at_another_location_returns_409(): void
    {
        $store = $this->store();
        $otherLocation = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        $data = $this->adjustment($store);
        $this->actingAs($store['user'])->postJson('/api/stock/adjust', $data)->assertOk();

        $this->postJson('/api/stock/adjust', array_replace($data, ['location_id' => $otherLocation->id]))->assertConflict();

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 13]);
        $this->assertDatabaseMissing('stocks', ['location_id' => $otherLocation->id]);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public static function alteredTransfers(): array
    {
        return ['quantity' => ['quantity'], 'source location' => ['from_location_id'], 'destination location' => ['to_location_id']];
    }

    #[DataProvider('alteredTransfers')]
    public function test_altered_transfer_retry_returns_409_without_changing_either_location(string $field): void
    {
        $store = $this->store();
        $destination = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        $data = $this->transfer($store, $destination);
        $this->actingAs($store['user'])->postJson('/api/transfers', $data)->assertOk();
        $changed = match ($field) {
            'quantity' => 4,
            default => Location::factory()->create(['tenant_id' => $store['tenant']->id])->id,
        };

        $this->postJson('/api/transfers', array_replace($data, [$field => $changed]))->assertConflict();

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 7]);
        $this->assertDatabaseHas('stocks', ['location_id' => $destination->id, 'product_id' => $store['product']->id, 'quantity' => 3]);
        $this->assertDatabaseCount('stocks', 2);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_transfer_key_reuse_for_another_product_returns_409(): void
    {
        $store = $this->store();
        $destination = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        $otherProduct = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $data = $this->transfer($store, $destination);
        $this->actingAs($store['user'])->postJson('/api/transfers', $data)->assertOk();

        $this->postJson('/api/transfers', array_replace($data, ['product_id' => $otherProduct->id]))->assertConflict();

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 7]);
        $this->assertDatabaseMissing('stocks', ['product_id' => $otherProduct->id]);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_another_authorized_actor_cannot_reuse_a_stock_change_key_and_receives_409(): void
    {
        $store = $this->store();
        $otherUser = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'admin']);
        $data = $this->adjustment($store);
        $this->actingAs($store['user'])->postJson('/api/stock/adjust', $data)->assertOk();

        $this->actingAs($otherUser)->postJson('/api/stock/adjust', $data)->assertConflict();

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 13]);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_adjustment_key_cannot_be_reused_for_transfer_and_returns_409(): void
    {
        $store = $this->store();
        $destination = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        $data = $this->adjustment($store);
        $this->actingAs($store['user'])->postJson('/api/stock/adjust', $data)->assertOk();

        $this->postJson('/api/transfers', $this->transfer($store, $destination, ['idempotency_key' => $data['idempotency_key']]))->assertConflict();

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 13]);
        $this->assertDatabaseMissing('stocks', ['location_id' => $destination->id]);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_same_key_is_independent_for_another_company(): void
    {
        $first = $this->store();
        $second = $this->store();

        $this->actingAs($first['user'])->postJson('/api/stock/adjust', $this->adjustment($first))->assertOk();
        $this->actingAs($second['user'])->postJson('/api/stock/adjust', $this->adjustment($second))->assertOk();
        $this->postJson('/api/stock/adjust', $this->adjustment($second))->assertOk()->assertJsonPath('stocks.0.quantity', 13);

        $this->assertDatabaseHas('stocks', ['id' => $first['stock']->id, 'quantity' => 13]);
        $this->assertDatabaseHas('stocks', ['id' => $second['stock']->id, 'quantity' => 13]);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public static function revokedAccess(): array
    {
        return ['demoted to cashier' => [['role' => 'cashier']], 'inactive' => [['is_active' => false]]];
    }

    #[DataProvider('revokedAccess')]
    public function test_adjustment_replay_still_requires_current_access_and_returns_403(array $changes): void
    {
        $store = $this->store();
        $data = $this->adjustment($store);
        $this->actingAs($store['user'])->postJson('/api/stock/adjust', $data)->assertOk();
        User::whereKey($store['user']->id)->update($changes);

        $this->postJson('/api/stock/adjust', $data)->assertForbidden();

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 13]);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_transfer_replay_after_demotion_returns_403_without_changing_either_location(): void
    {
        $store = $this->store();
        $destination = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        $data = $this->transfer($store, $destination);
        $this->actingAs($store['user'])->postJson('/api/transfers', $data)->assertOk();
        User::whereKey($store['user']->id)->update(['role' => 'cashier']);

        $this->postJson('/api/transfers', $data)->assertForbidden();

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 7]);
        $this->assertDatabaseHas('stocks', ['location_id' => $destination->id, 'product_id' => $store['product']->id, 'quantity' => 3]);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertSame(1, ActivityLog::where('action', 'inventory.transferred')->count());
    }

    public function test_failed_negative_adjustment_does_not_consume_key_and_corrected_retry_changes_stock_once(): void
    {
        $store = $this->store();
        $data = $this->adjustment($store, ['quantity' => -11]);
        Queue::fake([CheckLowStock::class]);

        $this->actingAs($store['user'])->postJson('/api/stock/adjust', $data)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
        $this->assertDatabaseCount('stock_movements', 0);
        Queue::assertNothingPushed();
        $data['quantity'] = -3;
        $this->postJson('/api/stock/adjust', $data)->assertOk();
        $this->postJson('/api/stock/adjust', $data)->assertOk()->assertJsonPath('stocks.0.quantity', 7);

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 7]);
        $this->assertDatabaseCount('stock_movements', 1);
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    public function test_failed_second_transfer_leg_rolls_back_both_locations_and_allows_same_key_retry(): void
    {
        $store = $this->store();
        $destination = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        $data = $this->transfer($store, $destination);
        Queue::fake([CheckLowStock::class]);
        Event::listen('eloquent.creating: '.StockMovement::class, function (StockMovement $movement): void {
            if ($movement->type === 'transfer_in') {
                throw ValidationException::withMessages(['items' => 'The receiving location is temporarily unavailable.']);
            }
        });

        $this->actingAs($store['user'])->postJson('/api/transfers', $data)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
        $this->assertDatabaseMissing('stocks', ['location_id' => $destination->id]);
        $this->assertDatabaseCount('stock_movements', 0);
        Event::forget('eloquent.creating: '.StockMovement::class);
        $this->postJson('/api/transfers', $data)->assertOk();
        $checks = Queue::pushed(CheckLowStock::class)->count();
        $this->postJson('/api/transfers', $data)->assertOk();

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 7]);
        $this->assertDatabaseHas('stocks', ['location_id' => $destination->id, 'product_id' => $store['product']->id, 'quantity' => 3]);
        $this->assertDatabaseCount('stock_movements', 2);
        Queue::assertPushed(CheckLowStock::class, $checks);
    }

    public static function invalidKeys(): array
    {
        return ['too short' => ['short'], 'too long' => [str_repeat('k', 101)], 'wrong type' => [['nested-key']]];
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_explicit_key_returns_422_without_changing_stock(mixed $key): void
    {
        $store = $this->store();

        $this->actingAs($store['user'])->postJson('/api/stock/adjust', $this->adjustment($store, ['idempotency_key' => $key]))
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_invalid_transfer_key_returns_422_without_changing_stock(): void
    {
        $store = $this->store();
        $destination = Location::factory()->create(['tenant_id' => $store['tenant']->id]);

        $this->actingAs($store['user'])->postJson('/api/transfers', $this->transfer($store, $destination, ['idempotency_key' => ['not-a-key']]))
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
        $this->assertDatabaseMissing('stocks', ['location_id' => $destination->id]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_legacy_unkeyed_adjustments_and_transfers_remain_independent_changes(): void
    {
        $store = $this->store();
        $destination = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        $adjustment = $this->adjustment($store);
        unset($adjustment['idempotency_key']);
        $transfer = $this->transfer($store, $destination, ['idempotency_key' => null]);

        $this->actingAs($store['user'])->postJson('/api/stock/adjust', $adjustment)->assertOk();
        $this->postJson('/api/stock/adjust', $adjustment)->assertOk()->assertJsonPath('stocks.0.quantity', 16);
        $this->postJson('/api/transfers', $transfer)->assertOk();
        $this->postJson('/api/transfers', $transfer)->assertOk();

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
        $this->assertDatabaseHas('stocks', ['location_id' => $destination->id, 'product_id' => $store['product']->id, 'quantity' => 6]);
        $this->assertDatabaseCount('stock_movements', 6);
    }

    public function test_additive_migration_preserves_existing_stock_history_and_keyed_changes_remain_independent(): void
    {
        $store = $this->store();
        $migration = require database_path('migrations/2026_10_11_213935_add_idempotency_to_stock_movements_table.php');
        $migration->down();
        $oldMovement = StockMovement::create(['tenant_id' => $store['tenant']->id, 'product_id' => $store['product']->id,
            'location_id' => $store['location']->id, 'user_id' => $store['user']->id, 'type' => 'adjustment',
            'quantity' => 2, 'reason' => 'Earlier count', 'reference' => 'HISTORY-001']);
        $oldAttributes = $oldMovement->getAttributes();

        $migration->up();
        $this->actingAs($store['user'])->postJson('/api/stock/adjust', $this->adjustment($store))->assertOk();
        $this->postJson('/api/stock/adjust', $this->adjustment($store))->assertOk()->assertJsonPath('stocks.0.quantity', 13);

        $this->assertDatabaseHas('stock_movements', $oldAttributes);
        $this->assertDatabaseHas('stock_movements', ['id' => $oldMovement->id, 'idempotency_key' => null, 'request_hash' => null]);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 13]);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_keyed_adjustment_for_another_company_location_returns_404_and_does_not_consume_key(): void
    {
        $store = $this->store();
        $foreignLocation = Location::factory()->create();
        $data = $this->adjustment($store, ['location_id' => $foreignLocation->id]);

        $this->actingAs($store['user'])->postJson('/api/stock/adjust', $data)->assertNotFound();
        $this->assertDatabaseCount('stock_movements', 0);
        $data['location_id'] = $store['location']->id;
        $this->postJson('/api/stock/adjust', $data)->assertOk()->assertJsonPath('stocks.0.quantity', 13);

        $this->assertDatabaseMissing('stocks', ['location_id' => $foreignLocation->id]);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_keyed_transfer_to_another_company_returns_404_without_changing_stock(): void
    {
        $store = $this->store();
        $foreignLocation = Location::factory()->create();

        $this->actingAs($store['user'])->postJson('/api/transfers', $this->transfer($store, $foreignLocation))->assertNotFound();

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
        $this->assertDatabaseMissing('stocks', ['location_id' => $foreignLocation->id]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_missing_adjustment_target_returns_422_and_corrected_same_key_request_succeeds(): void
    {
        $store = $this->store();
        $data = $this->adjustment($store);
        unset($data['product_id'], $data['location_id']);

        $this->actingAs($store['user'])->postJson('/api/stock/adjust', $data)->assertUnprocessable()->assertJsonValidationErrors(['product_id', 'location_id']);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->postJson('/api/stock/adjust', $this->adjustment($store))->assertOk()->assertJsonPath('stocks.0.quantity', 13);

        $this->assertDatabaseCount('stock_movements', 1);
    }
}
