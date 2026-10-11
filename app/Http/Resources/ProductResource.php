<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'sku' => $this->sku,
            'barcode' => $this->barcode, 'category' => $this->category,
            'price' => $this->price_cents / 100,
            'wholesale_price' => $this->wholesale_price_cents === null ? null : $this->wholesale_price_cents / 100,
            'cost' => $request->user()?->role === 'cashier' ? 0 : $this->cost_cents / 100,
            'reorder_level' => $this->reorder_level, 'color' => $this->color,
            'variant' => $this->variant,
            'product_family_id' => $this->product_family_id,
            'family_name' => $this->resource->relationLoaded('family') ? $this->family?->name : null,
            'option_names' => $this->resource->relationLoaded('family') ? ($this->family?->option_names ?? []) : [],
            'variant_options' => $this->variant_options ?? (object) [],
            'stocks' => $this->stocks->map(fn ($stock): array => [
                'location_id' => $stock->location_id, 'quantity' => $stock->quantity, 'on_hand' => $stock->quantity,
                'reserved_quantity' => $stock->reserved_quantity,
                'available_quantity' => max(0, $stock->quantity - $stock->reserved_quantity),
            ])->all(),
        ];
    }
}
