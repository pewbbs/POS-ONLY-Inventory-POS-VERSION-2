<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'is_demo', 'settings', 'operations_paused'])]
class Tenant extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['is_demo' => 'boolean', 'settings' => 'array', 'operations_paused' => 'boolean'];
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public static function defaultSettings(string $plan = 'grow'): array
    {
        return ['currency' => 'PHP', 'tax_percent' => 12, 'low_stock_alerts' => true,
            'receipt_footer' => 'Thank you for shopping with us.', 'receipt_address' => null, 'receipt_phone' => null, 'plan' => $plan,
            'billing_cycle' => 'monthly', 'subscription_status' => 'trial',
            'trial_ends_at' => now()->addDays(14)->toDateString()];
    }

    public function publicSettings(): array
    {
        $settings = array_replace(['receipt_address' => null, 'receipt_phone' => null], $this->settings ?? []);
        unset($settings['receipt_logo_path']);
        $path = $this->receiptLogoPath();
        $settings['receipt_logo_url'] = $path === null ? null : route('settings.receipt-logo', ['v' => substr(hash('sha256', $path), 0, 16)], false);

        return $settings;
    }

    public function receiptLogoPath(): ?string
    {
        $path = $this->settings['receipt_logo_path'] ?? null;

        return is_string($path) && preg_match('/^receipt-logos\/tenant-'.(int) $this->id.'\/[a-f0-9-]{36}\.(png|jpg|webp)$/D', $path) === 1 ? $path : null;
    }
}
