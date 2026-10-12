<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['tenant_id', 'location_id', 'user_id', 'customer_id', 'number', 'status', 'idempotency_key', 'request_hash', 'items', 'payments', 'subtotal_cents', 'discount_percent', 'discount_cents', 'tax_percent', 'tax_cents', 'total_cents', 'paid_cents', 'change_cents', 'returned_at', 'returned_cents', 'price_tier', 'cash_session_id', 'source', 'channel_id', 'offline_recorded_at', 'offline_synced_at', 'offline_request_hash'])]
class Sale extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['items' => 'array', 'payments' => 'array', 'returned_at' => 'datetime', 'returned_cents' => 'integer', 'offline_recorded_at' => 'datetime', 'offline_synced_at' => 'datetime'];
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class)->orderBy('id');
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(CommerceChannel::class, 'channel_id');
    }

    public function channelOrder(): HasOne
    {
        return $this->hasOne(ChannelOrder::class);
    }
}
