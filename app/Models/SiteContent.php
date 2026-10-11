<?php

namespace App\Models;

use Database\Factories\SiteContentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'draft', 'published', 'version', 'published_at'])]
class SiteContent extends Model
{
    /** @use HasFactory<SiteContentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['draft' => 'array', 'published' => 'array', 'version' => 'integer', 'published_at' => 'immutable_datetime'];
    }
}
