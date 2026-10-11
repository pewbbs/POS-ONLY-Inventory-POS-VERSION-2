<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'product_id', 'location_id', 'user_id', 'quantity', 'type', 'reason', 'reference'])]
class StockMovement extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
