<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'cash_session_id', 'user_id', 'direction', 'amount_cents', 'reason', 'idempotency_key', 'request_hash'])]
class CashAdjustment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }
}
