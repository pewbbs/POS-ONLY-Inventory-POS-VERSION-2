<?php

namespace App\Models;

use Database\Factories\PayMongoWebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayMongoWebhookEvent extends Model
{
    /** @use HasFactory<PayMongoWebhookEventFactory> */
    use HasFactory;

    protected $table = 'paymongo_webhook_events';

    protected $fillable = ['event_key', 'payload_digest', 'session_id', 'reference', 'status', 'processed_at', 'failure_summary', 'updated_at'];

    protected $hidden = ['event_key', 'payload_digest', 'reference'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
