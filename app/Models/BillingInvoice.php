<?php

namespace App\Models;

use Database\Factories\BillingInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['tenant_id', 'subscription_id', 'number', 'plan', 'billing_cycle', 'amount_cents', 'currency', 'status', 'due_at', 'paid_at', 'period_start', 'period_end', 'payment_method', 'payment_key', 'paid_by', 'payment_provider', 'payment_mode'])]
class BillingInvoice extends Model
{
    /** @use HasFactory<BillingInvoiceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'paid_at' => 'datetime', 'period_start' => 'datetime', 'period_end' => 'datetime'];
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(BillingPaymentAttempt::class);
    }

    public function latestPaymentAttempt(): HasOne
    {
        return $this->hasOne(BillingPaymentAttempt::class)->latestOfMany();
    }

    public function confirmedPaymentAttempt(): HasOne
    {
        return $this->hasOne(BillingPaymentAttempt::class)->ofMany(['id' => 'max'], fn (Builder $query): Builder => $query->where('status', 'paid'));
    }
}
