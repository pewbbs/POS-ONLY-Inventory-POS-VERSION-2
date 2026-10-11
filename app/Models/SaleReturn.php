<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'sale_id', 'user_id', 'number', 'idempotency_key', 'request_hash', 'reason', 'items', 'subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents'])]
class SaleReturn extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['items' => 'array', 'subtotal_cents' => 'integer', 'discount_cents' => 'integer', 'tax_cents' => 'integer', 'total_cents' => 'integer'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
