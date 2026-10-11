<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'product_id', 'location_id', 'quantity', 'reserved_quantity'])]
class Stock extends Model
{
    use HasFactory;

    protected $attributes = ['reserved_quantity' => 0];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'reserved_quantity' => 'integer'];
    }
}
