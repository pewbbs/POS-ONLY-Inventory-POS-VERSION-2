<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'name', 'option_names', 'defaults', 'creation_key', 'creation_hash'])]
class ProductFamily extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['option_names' => 'array', 'defaults' => 'array'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->orderBy('id');
    }
}
