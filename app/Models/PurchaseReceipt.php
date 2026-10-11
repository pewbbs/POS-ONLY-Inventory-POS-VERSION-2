<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'purchase_order_id', 'user_id', 'number', 'idempotency_key', 'request_hash', 'items', 'note'])]
class PurchaseReceipt extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['items' => 'array'];
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
