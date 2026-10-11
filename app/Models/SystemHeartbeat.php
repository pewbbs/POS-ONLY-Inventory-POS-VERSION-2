<?php

namespace App\Models;

use Database\Factories\SystemHeartbeatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemHeartbeat extends Model
{
    /** @use HasFactory<SystemHeartbeatFactory> */
    use HasFactory;

    protected $fillable = ['component', 'connection', 'queue', 'scope_key', 'last_seen_at', 'created_at', 'updated_at'];

    protected $hidden = ['scope_key'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
