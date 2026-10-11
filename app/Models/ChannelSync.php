<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'commerce_channel_id', 'user_id', 'idempotency_key', 'request_hash', 'status', 'message', 'listing_count'])]
class ChannelSync extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['listing_count' => 'integer'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(CommerceChannel::class, 'commerce_channel_id');
    }
}
