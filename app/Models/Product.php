<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'name', 'sku', 'barcode', 'category', 'price_cents', 'wholesale_price_cents', 'cost_cents', 'reorder_level', 'color', 'variant', 'product_family_id', 'variant_options', 'variant_key'])]
class Product extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'wholesale_price_cents' => 'integer', 'cost_cents' => 'integer', 'reorder_level' => 'integer', 'variant_options' => 'array'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }
}
