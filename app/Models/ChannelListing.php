<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'commerce_channel_id', 'product_id', 'sku', 'name', 'price_cents', 'published_quantity'])]
class ChannelListing extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'published_quantity' => 'integer'];
    }
}
