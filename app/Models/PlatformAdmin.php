<?php

namespace App\Models;

use Database\Factories\PlatformAdminFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['name', 'email', 'password', 'is_active', 'setup_token_hash', 'setup_expires_at', 'remember_token'])]
class PlatformAdmin extends Authenticatable
{
    /** @use HasFactory<PlatformAdminFactory> */
    use HasFactory;

    protected $hidden = ['password', 'remember_token', 'setup_token_hash', 'setup_expires_at'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean', 'setup_expires_at' => 'datetime'];
    }
}
