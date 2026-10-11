<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'supplier_id', 'location_id', 'user_id', 'number', 'status', 'items', 'total_cents', 'received_at', 'source', 'reorder_rule_id', 'approved_at', 'cancelled_at'])]
class PurchaseOrder extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['items' => 'array', 'received_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function receiptItems(): array
    {
        return array_map(function (array $item): array {
            $received = (int) ($item['received_quantity'] ?? ($this->status === 'received' ? $item['quantity'] : 0));

            return array_replace($item, ['received_quantity' => $received, 'remaining_quantity' => max(0, (int) $item['quantity'] - $received)]);
        }, $this->items);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class)->orderByDesc('id');
    }
}
