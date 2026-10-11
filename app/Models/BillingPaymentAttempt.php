<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BillingPaymentAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPaymentAttempt extends Model
{
    /** @use HasFactory<BillingPaymentAttemptFactory> */
    use HasFactory;

    protected $fillable = ['tenant_id', 'billing_invoice_id', 'initiated_by', 'reference', 'active_scope', 'status', 'session_id', 'payment_id', 'amount_cents', 'currency', 'mode', 'checkout_url', 'checkout_payload', 'last_requested_at', 'confirmed_at', 'failure_summary', 'created_at', 'updated_at'];

    protected $hidden = ['tenant_id', 'initiated_by', 'active_scope', 'checkout_url', 'checkout_payload', 'last_requested_at'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'checkout_url' => 'encrypted', 'checkout_payload' => 'array', 'last_requested_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function safeRecord(): array
    {
        return ['id' => $this->id, 'invoice_id' => $this->billing_invoice_id, 'status' => $this->status,
            'reference' => $this->reference, 'session_id' => $this->session_id, 'payment_id' => $this->payment_id,
            'amount' => $this->amount_cents / 100, 'currency' => $this->currency, 'mode' => $this->mode,
            'created_at' => $this->safeTimestamp('created_at'), 'confirmed_at' => $this->safeTimestamp('confirmed_at'),
            'failure_summary' => $this->failure_summary];
    }

    private function safeTimestamp(string $attribute): ?string
    {
        $timestamp = $this->getRawOriginal($attribute);

        return $timestamp === null ? null : CarbonImmutable::parse($timestamp, config('app.timezone', 'UTC'))->toIso8601String();
    }
}
