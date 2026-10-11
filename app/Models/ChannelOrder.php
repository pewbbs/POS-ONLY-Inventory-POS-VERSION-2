<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'commerce_channel_id', 'location_id', 'user_id', 'sale_id', 'external_reference', 'idempotency_key', 'request_hash', 'status', 'items', 'subtotal_cents', 'discount_cents', 'tax_percent', 'tax_cents', 'total_cents', 'imported_at', 'canceled_at'])]
class ChannelOrder extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['items' => 'array', 'subtotal_cents' => 'integer', 'discount_cents' => 'integer', 'tax_cents' => 'integer', 'total_cents' => 'integer', 'tax_percent' => 'decimal:2', 'imported_at' => 'datetime', 'canceled_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(CommerceChannel::class, 'commerce_channel_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
