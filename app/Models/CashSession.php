<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'location_id', 'user_id', 'status', 'open_scope', 'opening_key', 'opening_hash', 'opening_float_cents', 'cash_sales_cents', 'cash_in_cents', 'cash_out_cents', 'expected_cash_cents', 'counted_cash_cents', 'variance_cents', 'close_reason', 'closing_key', 'closing_hash', 'opened_at', 'closed_at'])]
class CashSession extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['opening_float_cents' => 'integer', 'cash_sales_cents' => 'integer', 'cash_in_cents' => 'integer', 'cash_out_cents' => 'integer', 'expected_cash_cents' => 'integer', 'counted_cash_cents' => 'integer', 'variance_cents' => 'integer', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function expectedCash(): int
    {
        return $this->expected_cash_cents ?? ($this->opening_float_cents + $this->cash_sales_cents + $this->cash_in_cents - $this->cash_out_cents);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(CashAdjustment::class)->orderBy('id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
