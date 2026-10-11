<?php

namespace Tests\Feature;

use App\Models\CashSession;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CashSessionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function store(string $role = 'cashier'): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        Stock::create(['tenant_id' => $tenant->id, 'location_id' => $location->id, 'product_id' => $product->id, 'quantity' => 10]);

        return compact('tenant', 'user', 'location', 'product');
    }

    private function opening(array $store, string $key = 'cash-shift-open-001'): array
    {
        return ['location_id' => $store['location']->id, 'opening_float' => 100, 'idempotency_key' => $key];
    }

    private function checkout(array $store, array $changes = []): array
    {
        return array_replace(['location_id' => $store['location']->id, 'items' => [['product_id' => $store['product']->id, 'quantity' => 2]], 'discount_percent' => 10, 'tax_percent' => 12, 'payments' => [['method' => 'cash', 'amount' => 1000]], 'idempotency_key' => 'shift-checkout-key'], $changes);
    }

    private function open(array $store): int
    {
        return $this->actingAs($store['user'])->postJson('/api/cash-sessions/open', $this->opening($store))->assertCreated()->json('id');
    }

    public function test_cashier_can_open_one_shift_per_location_and_retry_same_key(): void
    {
        $store = $this->store();
        $id = $this->open($store);
        $this->postJson('/api/cash-sessions/open', $this->opening($store))->assertCreated()->assertJsonPath('id', $id)->assertJsonPath('expected_cash', 100);
        $this->postJson('/api/cash-sessions/open', $this->opening($store, 'different-opening-key'))->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $this->postJson('/api/cash-sessions/open', array_replace($this->opening($store), ['opening_float' => 101]))->assertConflict();
        $this->assertDatabaseCount('cash_sessions', 1);
        $this->getJson('/api/cash-sessions?location_id='.$store['location']->id)->assertOk()->assertJsonPath('current.id', $id)->assertJsonCount(1, 'history');
    }

    public function test_checkout_records_cash_tender_minus_change_once_and_keeps_optional_flow(): void
    {
        $store = $this->store();
        $id = $this->open($store);
        $data = $this->checkout($store);
        $sale = $this->postJson('/api/sales', $data)->assertCreated()->assertJsonPath('cash_session_id', $id)->assertJsonPath('change', 92.8);
        $this->postJson('/api/sales', $data)->assertCreated()->assertJsonPath('id', $sale->json('id'));
        $this->getJson('/api/cash-sessions?location_id='.$store['location']->id)->assertOk()->assertJsonPath('current.cash_sales', 907.2)->assertJsonPath('current.expected_cash', 1007.2);
        $this->assertDatabaseHas('cash_sessions', ['id' => $id, 'cash_sales_cents' => 90720]);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('sales', ['id' => $sale->json('id'), 'cash_session_id' => $id]);
    }

    public function test_split_tender_counts_only_physical_cash_after_change(): void
    {
        $store = $this->store();
        $id = $this->open($store);
        $this->postJson('/api/sales', $this->checkout($store, ['payments' => [['method' => 'card', 'amount' => 500], ['method' => 'cash', 'amount' => 500]]]))->assertCreated()->assertJsonPath('cash_session_id', $id)->assertJsonPath('change', 92.8);
        $this->getJson('/api/cash-sessions?location_id='.$store['location']->id)->assertOk()->assertJsonPath('current.cash_sales', 407.2)->assertJsonPath('current.expected_cash', 507.2);
    }

    public function test_non_cash_sale_does_not_add_drawer_cash(): void
    {
        $store = $this->store();
        $id = $this->open($store);
        $this->postJson('/api/sales', $this->checkout($store, ['payments' => [['method' => 'wallet', 'amount' => 907.2]]]))->assertCreated()->assertJsonPath('cash_session_id', $id);
        $this->assertDatabaseHas('cash_sessions', ['id' => $id, 'cash_sales_cents' => 0]);
    }

    public function test_checkout_without_own_open_shift_works_without_linking_someone_elses_session(): void
    {
        $store = $this->store();
        $other = User::factory()->create(['tenant_id' => $store['tenant']->id]);
        $session = CashSession::factory()->create(['tenant_id' => $store['tenant']->id, 'user_id' => $other->id, 'location_id' => $store['location']->id]);
        $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store))->assertCreated()->assertJsonPath('cash_session_id', null);
        $this->assertSame(0, $session->refresh()->cash_sales_cents);
    }

    public function test_shift_at_another_location_does_not_capture_sale_cash(): void
    {
        $store = $this->store();
        $otherLocation = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        $session = CashSession::factory()->create(['tenant_id' => $store['tenant']->id, 'user_id' => $store['user']->id, 'location_id' => $otherLocation->id]);
        $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store))->assertCreated()->assertJsonPath('cash_session_id', null);
        $this->assertSame(0, $session->refresh()->cash_sales_cents);
    }

    public function test_recorded_cash_movements_require_reasons_and_are_idempotent(): void
    {
        $store = $this->store();
        $id = $this->open($store);
        $in = ['direction' => 'in', 'amount' => 25.25, 'reason' => 'Extra change', 'idempotency_key' => 'drawer-cash-in-001'];
        $out = ['direction' => 'out', 'amount' => 10, 'reason' => 'Actual cash refund', 'idempotency_key' => 'drawer-cash-out-001'];
        $this->postJson('/api/cash-sessions/'.$id.'/adjustments', $in)->assertOk()->assertJsonPath('cash_in', 25.25);
        $this->postJson('/api/cash-sessions/'.$id.'/adjustments', $out)->assertOk()->assertJsonPath('cash_out', 10)->assertJsonPath('expected_cash', 115.25)->assertJsonCount(2, 'adjustments');
        $this->postJson('/api/cash-sessions/'.$id.'/adjustments', $out)->assertOk()->assertJsonPath('expected_cash', 115.25)->assertJsonCount(2, 'adjustments');
        $this->postJson('/api/cash-sessions/'.$id.'/adjustments', array_replace($out, ['amount' => 11]))->assertConflict();
        $this->assertDatabaseCount('cash_adjustments', 2);
        $this->assertDatabaseHas('cash_adjustments', ['cash_session_id' => $id, 'direction' => 'out', 'amount_cents' => 1000, 'reason' => 'Actual cash refund']);
    }

    public function test_cash_out_cannot_exceed_expected_balance(): void
    {
        $store = $this->store();
        $id = $this->open($store);
        $this->postJson('/api/cash-sessions/'.$id.'/adjustments', ['direction' => 'out', 'amount' => 100.01, 'reason' => 'Too much', 'idempotency_key' => 'excessive-cash-out'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('cash_adjustments', 0);
        $this->assertDatabaseHas('cash_sessions', ['id' => $id, 'cash_out_cents' => 0]);
    }

    public function test_close_snapshots_count_expected_cash_and_signed_variance_once(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $id = $this->open($store);
        $this->postJson('/api/sales', $this->checkout($store))->assertCreated();
        $close = ['counted_cash' => 1000, 'reason' => 'Seven pesos and twenty centavos short', 'idempotency_key' => 'close-count-key-001'];
        $this->postJson('/api/cash-sessions/'.$id.'/close', $close)->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('expected_cash', 1007.2)->assertJsonPath('counted_cash', 1000)->assertJsonPath('variance', -7.2);
        $this->travel(1)->hours();
        $this->postJson('/api/cash-sessions/'.$id.'/close', $close)->assertOk()->assertJsonPath('variance', -7.2);
        $this->postJson('/api/cash-sessions/'.$id.'/close', array_replace($close, ['counted_cash' => 1001]))->assertConflict();
        $this->assertDatabaseHas('cash_sessions', ['id' => $id, 'status' => 'closed', 'open_scope' => null, 'expected_cash_cents' => 100720, 'counted_cash_cents' => 100000, 'variance_cents' => -720]);
        $this->getJson('/api/cash-sessions?location_id='.$store['location']->id)->assertOk()->assertJsonPath('current', null)->assertJsonPath('history.0.variance', -7.2);
    }

    public function test_new_shift_after_close_does_not_change_old_count_or_capture_later_sales(): void
    {
        $store = $this->store();
        $oldId = $this->open($store);
        $this->postJson('/api/cash-sessions/'.$oldId.'/close', ['counted_cash' => 105, 'idempotency_key' => 'closed-shift-key'])->assertOk()->assertJsonPath('variance', 5);
        $newId = $this->postJson('/api/cash-sessions/open', $this->opening($store, 'new-cash-shift-key'))->assertCreated()->json('id');
        $this->postJson('/api/sales', $this->checkout($store))->assertCreated()->assertJsonPath('cash_session_id', $newId);
        $this->assertDatabaseHas('cash_sessions', ['id' => $oldId, 'cash_sales_cents' => 0, 'expected_cash_cents' => 10000, 'variance_cents' => 500]);
        $this->assertDatabaseHas('cash_sessions', ['id' => $newId, 'cash_sales_cents' => 90720]);
        $this->postJson('/api/cash-sessions/open', $this->opening($store))->assertCreated()->assertJsonPath('id', $oldId)->assertJsonPath('status', 'closed');
    }

    public function test_old_adjustment_replay_after_close_is_safe_but_new_movements_are_rejected(): void
    {
        $store = $this->store();
        $id = $this->open($store);
        $data = ['direction' => 'in', 'amount' => 10, 'reason' => 'Change', 'idempotency_key' => 'old-shift-movement'];
        $this->postJson('/api/cash-sessions/'.$id.'/adjustments', $data)->assertOk();
        $this->postJson('/api/cash-sessions/'.$id.'/close', ['counted_cash' => 110, 'idempotency_key' => 'close-adjusted-shift'])->assertOk();
        $this->postJson('/api/cash-sessions/'.$id.'/adjustments', $data)->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('expected_cash', 110);
        $this->postJson('/api/cash-sessions/'.$id.'/adjustments', array_replace($data, ['idempotency_key' => 'new-closed-shift-movement']))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('cash_adjustments', 1);
    }

    public function test_return_does_not_automatically_execute_or_deduct_cash_refund(): void
    {
        $store = $this->store('manager');
        $id = $this->open($store);
        $sale = $this->postJson('/api/sales', $this->checkout($store))->assertCreated();
        $this->postJson('/api/sales/'.$sale->json('id').'/return', [])->assertOk()->assertJsonPath('status', 'returned');
        $this->getJson('/api/cash-sessions?location_id='.$store['location']->id)->assertOk()->assertJsonPath('current.expected_cash', 1007.2)->assertJsonPath('current.cash_out', 0);
        $this->postJson('/api/cash-sessions/'.$id.'/adjustments', ['direction' => 'out', 'amount' => 907.2, 'reason' => 'Cash refund paid for receipt', 'idempotency_key' => 'manual-cash-refund'])->assertOk()->assertJsonPath('expected_cash', 100);
    }

    public function test_cross_user_and_cross_tenant_shifts_are_hidden_and_cannot_be_changed(): void
    {
        $store = $this->store();
        $id = $this->open($store);
        $other = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'admin']);
        $data = ['direction' => 'out', 'amount' => 10, 'reason' => 'Not your drawer', 'idempotency_key' => 'cross-user-movement'];
        $this->actingAs($other)->getJson('/api/cash-sessions?location_id='.$store['location']->id)->assertOk()->assertJsonPath('current', null)->assertJsonCount(0, 'history');
        $this->postJson('/api/cash-sessions/'.$id.'/adjustments', $data)->assertNotFound();
        $this->postJson('/api/cash-sessions/'.$id.'/close', ['counted_cash' => 100, 'idempotency_key' => 'cross-user-close'])->assertNotFound();
        $otherStore = $this->store();
        $this->actingAs($otherStore['user'])->getJson('/api/cash-sessions?location_id='.$store['location']->id)->assertNotFound();
        $this->postJson('/api/cash-sessions/'.$id.'/adjustments', $data)->assertNotFound();
        $this->assertDatabaseHas('cash_sessions', ['id' => $id, 'status' => 'open', 'cash_out_cents' => 0]);
        $this->assertDatabaseCount('cash_adjustments', 0);
    }

    public function test_failed_checkout_does_not_change_expected_cash_or_link_sale(): void
    {
        $store = $this->store();
        $id = $this->open($store);
        $this->postJson('/api/sales', $this->checkout($store, ['items' => [['product_id' => $store['product']->id, 'quantity' => 11]]]))->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('cash_sessions', ['id' => $id, 'cash_sales_cents' => 0]);
    }

    public function test_cash_endpoints_require_authentication_and_validate_reason_money_and_key(): void
    {
        $this->getJson('/api/cash-sessions?location_id=1')->assertUnauthorized();
        $this->postJson('/api/cash-sessions/open', [])->assertUnauthorized();
        $store = $this->store();
        $id = $this->open($store);
        $this->postJson('/api/cash-sessions/'.$id.'/adjustments', ['direction' => 'sideways', 'amount' => 0, 'idempotency_key' => 'short'])->assertUnprocessable()->assertJsonValidationErrors(['direction', 'amount', 'reason', 'idempotency_key']);
        $this->postJson('/api/cash-sessions/'.$id.'/close', ['counted_cash' => -1, 'idempotency_key' => 'negative-count-key'])->assertUnprocessable()->assertJsonValidationErrors('counted_cash');
        $this->assertDatabaseCount('cash_adjustments', 0);
        $this->assertDatabaseHas('cash_sessions', ['id' => $id, 'status' => 'open']);
    }
}
