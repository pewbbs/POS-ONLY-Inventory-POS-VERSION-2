<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'product_family_id', 'user_id', 'idempotency_key', 'request_hash', 'product_ids'])]
class ProductVariantBatch extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['product_ids' => 'array'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id');
    }
}
