<?php

namespace App\Models;

use Database\Factories\MonitoringEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringEvent extends Model
{
    /** @use HasFactory<MonitoringEventFactory> */
    use HasFactory;

    protected $fillable = ['tenant_id', 'kind', 'status', 'summary', 'error_class', 'error_code', 'pending_scope', 'completed_at', 'acknowledged_at', 'created_at', 'updated_at'];

    protected $hidden = ['tenant_id', 'pending_scope'];

    protected function casts(): array
    {
        return ['error_code' => 'integer', 'completed_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
