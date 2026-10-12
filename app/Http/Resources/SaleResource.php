<?php

namespace App\Http\Resources;

use App\Services\SaleRefundAllocator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $allocator = app(SaleRefundAllocator::class);
        $items = $allocator->saleItems($this->resource);
        $returnedCents = $this->status === 'returned' ? $this->total_cents : ($this->returned_cents ?? 0);
        $result = [
            'id' => $this->id, 'number' => $this->number, 'location_id' => $this->location_id,
            'customer_id' => $this->customer_id, 'status' => $this->status,
            'price_tier' => $this->price_tier ?? 'retail',
            'cash_session_id' => $this->cash_session_id,
            'source' => $this->source ?? 'pos',
            'simulated' => $this->source === 'channel',
            'channel_id' => $this->channel_id,
            'channel_name' => $this->channel_id ? $this->channel?->name : null,
            'external_reference' => $this->channel_id ? $this->channelOrder?->external_reference : null,
            'returned_amount' => $returnedCents / 100,
            'net_total' => ($this->total_cents - $returnedCents) / 100,
            'cashier_name' => $this->cashier?->name ?? 'Team member',
            'discount_percent' => (float) $this->discount_percent,
            'tax_percent' => (float) $this->tax_percent,
            'created_at' => $this->created_at->toIso8601String(),
            'offline' => $this->offline_recorded_at === null ? null : [
                'recorded_at' => $this->offline_recorded_at->toIso8601String(),
                'synced_at' => $this->offline_synced_at?->toIso8601String(),
            ],
            'returned_at' => $this->returned_at?->toIso8601String(),
            'items' => array_map(fn (array $item): array => [
                'product_id' => $item['product_id'], 'name' => $item['name'], 'sku' => $item['sku'],
                'quantity' => $item['quantity'], 'unit_price' => $item['unit_price_cents'] / 100,
                'line_total' => $item['line_total_cents'] / 100,
                'discount' => $item['discount_cents'] / 100, 'tax' => $item['tax_cents'] / 100, 'total' => $item['total_cents'] / 100,
                'returned_quantity' => $item['returned_quantity'], 'returnable_quantity' => $item['quantity'] - $item['returned_quantity'],
                'returnable_amount' => $allocator->refund($item, $item['quantity'] - $item['returned_quantity'])['total_cents'] / 100,
                'returned_amount' => ($item['total_cents'] - $allocator->refund($item, $item['quantity'] - $item['returned_quantity'])['total_cents']) / 100,
            ], $items),
            'returns' => $this->returns->map(fn ($record): array => (new SaleReturnResource($record))->resolve($request))->all(),
            'payments' => array_map(fn (array $payment): array => ['method' => $payment['method'], 'amount' => $payment['amount_cents'] / 100, 'simulated' => (bool) ($payment['simulated'] ?? false)], $this->payments),
        ];
        foreach (['subtotal', 'discount', 'tax', 'total', 'paid', 'change'] as $field) {
            $result[$field] = $this->{$field.'_cents'} / 100;
        }

        return $result;
    }
}
