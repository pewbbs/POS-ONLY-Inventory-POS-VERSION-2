<?php

namespace App\Services;

use App\Models\Sale;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

class SaleRefundAllocator
{
    private function portion(int $amount, int $numerator, int $denominator): int
    {
        if ($amount === 0 || $numerator === 0 || $denominator === 0) {
            return 0;
        }

        return BigInteger::of($amount)->multipliedBy($numerator)->dividedBy($denominator, RoundingMode::HalfUp)->toInt();
    }

    /** @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public function allocate(array $items, int $subtotal, int $discount, int $tax): array
    {
        $subtotalSoFar = 0;
        $discountSoFar = 0;
        $netSoFar = 0;
        $taxSoFar = 0;
        foreach ($items as &$item) {
            $subtotalSoFar += $item['line_total_cents'];
            $cumulativeDiscount = $this->portion($discount, $subtotalSoFar, $subtotal);
            $item['discount_cents'] = $cumulativeDiscount - $discountSoFar;
            $discountSoFar = $cumulativeDiscount;
            $netSoFar += $item['line_total_cents'] - $item['discount_cents'];
            $cumulativeTax = $this->portion($tax, $netSoFar, $subtotal - $discount);
            $item['tax_cents'] = $cumulativeTax - $taxSoFar;
            $taxSoFar = $cumulativeTax;
            $item['total_cents'] = $item['line_total_cents'] - $item['discount_cents'] + $item['tax_cents'];
            $item['returned_quantity'] ??= 0;
        }
        unset($item);

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    public function saleItems(Sale $sale): array
    {
        $items = $sale->items;
        if (! array_all($items, fn (array $item): bool => isset($item['discount_cents'], $item['tax_cents'], $item['total_cents']))) {
            $items = $this->allocate($items, $sale->subtotal_cents, $sale->discount_cents, $sale->tax_cents);
        }
        foreach ($items as &$item) {
            $item['product_id'] = (int) $item['product_id'];
            $item['quantity'] = (int) $item['quantity'];
            $item['returned_quantity'] = $sale->status === 'returned' ? $item['quantity'] : ($item['returned_quantity'] ?? 0);
        }
        unset($item);

        return $items;
    }

    /** @param array<string, mixed> $item
     * @return array{product_id: int, name: string, sku: string, quantity: int, subtotal_cents: int, discount_cents: int, tax_cents: int, total_cents: int}
     */
    public function refund(array $item, int $quantity): array
    {
        $returned = $item['returned_quantity'] ?? 0;
        $discount = $this->portion($item['discount_cents'], $returned + $quantity, $item['quantity']) - $this->portion($item['discount_cents'], $returned, $item['quantity']);
        $tax = $this->portion($item['tax_cents'], $returned + $quantity, $item['quantity']) - $this->portion($item['tax_cents'], $returned, $item['quantity']);
        $subtotal = $item['unit_price_cents'] * $quantity;

        return ['product_id' => $item['product_id'], 'name' => $item['name'], 'sku' => $item['sku'], 'quantity' => $quantity, 'subtotal_cents' => $subtotal, 'discount_cents' => $discount, 'tax_cents' => $tax, 'total_cents' => $subtotal - $discount + $tax];
    }
}
