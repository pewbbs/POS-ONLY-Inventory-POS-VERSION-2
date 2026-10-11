<?php

namespace App\Services;

use App\Models\CashAdjustment;
use App\Models\CashSession;
use App\Models\Location;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashSessionService
{
    private function lockTenant(User $user): void
    {
        Tenant::whereKey($user->tenant_id)->lockForUpdate()->firstOrFail();
        $actor = User::where('tenant_id', $user->tenant_id)->lockForUpdate()->findOrFail($user->id);
        abort_unless($actor->is_active, 403, 'Your workspace access is paused.');
    }

    private function ownSession(User $user, int $id): CashSession
    {
        return CashSession::where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function open(User $user, array $data): CashSession
    {
        $amount = InventoryService::cents($data['opening_float']);
        $hash = $this->hash(['user_id' => $user->id, 'location_id' => (int) $data['location_id'], 'opening_float' => $amount]);

        return DB::transaction(function () use ($user, $data, $amount, $hash): CashSession {
            $this->lockTenant($user);
            Location::where('tenant_id', $user->tenant_id)->findOrFail($data['location_id']);
            $existing = CashSession::where('tenant_id', $user->tenant_id)->where('opening_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->opening_hash, $hash), 409, 'This opening key was already used for another cash shift.');

                return $existing->load(['cashier', 'adjustments']);
            }
            if (CashSession::where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->where('location_id', $data['location_id'])->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['location_id' => 'Close your current cash shift at this location before opening another.']);
            }
            $session = CashSession::create(['tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'location_id' => $data['location_id'], 'open_scope' => $user->tenant_id.':'.$user->id.':'.$data['location_id'], 'opening_key' => $data['idempotency_key'], 'opening_hash' => $hash, 'opening_float_cents' => $amount, 'cash_sales_cents' => 0, 'cash_in_cents' => 0, 'cash_out_cents' => 0, 'status' => 'open', 'opened_at' => now()]);

            return $session->load(['cashier', 'adjustments']);
        }, 5);
    }

    public function adjust(User $user, int $id, array $data): CashSession
    {
        $amount = InventoryService::cents($data['amount']);
        $reason = trim($data['reason']);
        $hash = $this->hash(['direction' => $data['direction'], 'amount' => $amount, 'reason' => $reason]);

        return DB::transaction(function () use ($user, $id, $data, $amount, $reason, $hash): CashSession {
            $this->lockTenant($user);
            $session = $this->ownSession($user, $id);
            $existing = CashAdjustment::where('cash_session_id', $session->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This cash movement key was already used for another movement.');

                return $session->load(['cashier', 'adjustments']);
            }
            if ($session->status !== 'open') {
                throw ValidationException::withMessages(['amount' => 'This cash shift is closed. Open a new shift to record cash movements.']);
            }
            if ($data['direction'] === 'out' && $amount > $session->expectedCash()) {
                throw ValidationException::withMessages(['amount' => 'Cash out cannot exceed the expected cash balance.']);
            }
            CashAdjustment::create(['tenant_id' => $user->tenant_id, 'cash_session_id' => $session->id, 'user_id' => $user->id, 'direction' => $data['direction'], 'amount_cents' => $amount, 'reason' => $reason, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash]);
            $session->increment($data['direction'] === 'in' ? 'cash_in_cents' : 'cash_out_cents', $amount);

            return $session->refresh()->load(['cashier', 'adjustments']);
        }, 5);
    }

    public function close(User $user, int $id, array $data): CashSession
    {
        $counted = InventoryService::cents($data['counted_cash']);
        $reason = trim($data['reason'] ?? '') ?: null;
        $hash = $this->hash(['counted_cash' => $counted, 'reason' => $reason]);

        return DB::transaction(function () use ($user, $id, $data, $counted, $reason, $hash): CashSession {
            $this->lockTenant($user);
            $session = $this->ownSession($user, $id);
            if ($session->status === 'closed') {
                abort_unless($session->closing_key === $data['idempotency_key'] && hash_equals($session->closing_hash, $hash), 409, 'This cash shift has already closed with a different count.');

                return $session->load(['cashier', 'adjustments']);
            }
            $expected = $session->expectedCash();
            $session->update(['status' => 'closed', 'open_scope' => null, 'expected_cash_cents' => $expected, 'counted_cash_cents' => $counted, 'variance_cents' => $counted - $expected, 'close_reason' => $reason, 'closing_key' => $data['idempotency_key'], 'closing_hash' => $hash, 'closed_at' => now()]);

            return $session->refresh()->load(['cashier', 'adjustments']);
        }, 5);
    }

    /** Called within checkout's tenant transaction so closing and checkout cannot race. */
    public function attachToSale(User $user, Sale $sale): void
    {
        if ($sale->cash_session_id !== null) {
            return;
        }
        $session = CashSession::where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->where('location_id', $sale->location_id)->where('status', 'open')->lockForUpdate()->first();
        if (! $session) {
            return;
        }
        $cash = array_sum(array_column(array_filter($sale->payments, fn (array $payment): bool => $payment['method'] === 'cash'), 'amount_cents')) - $sale->change_cents;
        $session->increment('cash_sales_cents', $cash);
        $sale->update(['cash_session_id' => $session->id]);
    }
}
