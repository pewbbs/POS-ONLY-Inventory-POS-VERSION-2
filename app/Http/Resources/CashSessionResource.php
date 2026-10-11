<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'location_id' => $this->location_id, 'status' => $this->status,
            'cashier_name' => $this->cashier?->name ?? 'Team member',
            'opened_at' => $this->opened_at->toIso8601String(), 'closed_at' => $this->closed_at?->toIso8601String(),
            'opening_float' => $this->opening_float_cents / 100, 'cash_sales' => $this->cash_sales_cents / 100,
            'cash_in' => $this->cash_in_cents / 100, 'cash_out' => $this->cash_out_cents / 100,
            'expected_cash' => $this->expectedCash() / 100,
            'counted_cash' => $this->counted_cash_cents === null ? null : $this->counted_cash_cents / 100,
            'variance' => $this->variance_cents === null ? null : $this->variance_cents / 100,
            'close_reason' => $this->close_reason,
            'adjustments' => $this->adjustments->map(fn ($entry): array => ['id' => $entry->id, 'direction' => $entry->direction, 'amount' => $entry->amount_cents / 100, 'reason' => $entry->reason, 'created_at' => $entry->created_at->toIso8601String()])->all(),
        ];
    }
}
