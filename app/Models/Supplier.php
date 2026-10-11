<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'name', 'email', 'phone'])]
class Supplier extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [];
    }
}
