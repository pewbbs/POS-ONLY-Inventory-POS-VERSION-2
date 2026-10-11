<?php

namespace App\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'plan', 'billing_cycle', 'status', 'trial_ends_at', 'current_period_start', 'current_period_end', 'cancel_at_period_end', 'pending_plan', 'pending_billing_cycle'])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime', 'current_period_start' => 'datetime', 'current_period_end' => 'datetime', 'cancel_at_period_end' => 'boolean'];
    }
}
