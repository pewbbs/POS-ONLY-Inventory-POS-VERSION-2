<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = [
            'id' => $this->id, 'number' => $this->number,
            'reason' => $this->reason, 'actor_name' => $this->actor?->name ?? 'Team member',
            'created_at' => $this->created_at->toIso8601String(),
            'items' => array_map(fn (array $item): array => [
                'product_id' => $item['product_id'], 'name' => $item['name'], 'sku' => $item['sku'], 'quantity' => $item['quantity'],
                'subtotal' => $item['subtotal_cents'] / 100, 'discount' => $item['discount_cents'] / 100,
                'tax' => $item['tax_cents'] / 100, 'total' => $item['total_cents'] / 100,
            ], $this->items),
        ];
        foreach (['subtotal', 'discount', 'tax', 'total'] as $field) {
            $result[$field] = $this->{$field.'_cents'} / 100;
        }

        return $result;
    }
}
