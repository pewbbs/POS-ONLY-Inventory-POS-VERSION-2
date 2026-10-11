<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event', 'variant'])]
class AnalyticsEvent extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [];
    }
}
