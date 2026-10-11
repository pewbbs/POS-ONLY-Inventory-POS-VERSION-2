<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'location_id', 'user_id', 'name', 'kind', 'enabled', 'simulate_failure', 'pending_sync', 'sync_state', 'last_error', 'last_synced_at', 'creation_key', 'creation_hash'])]
class CommerceChannel extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'simulate_failure' => 'boolean', 'pending_sync' => 'boolean', 'last_synced_at' => 'datetime'];
    }

    public function listings(): HasMany
    {
        return $this->hasMany(ChannelListing::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
