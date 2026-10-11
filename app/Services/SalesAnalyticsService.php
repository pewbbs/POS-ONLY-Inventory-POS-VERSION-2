<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Sale;
use App\Models\User;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SalesAnalyticsService
{
    private const TIMEZONE = 'Asia/Manila';

    public function __construct(private SaleRefundAllocator $allocator) {}

    /** @return array{from: ?string, to: ?string, location_id: ?int, source: string, rank_by: string} */
    public function validatedFilters(Request $request): array
    {
        $data = Validator::make($request->query(), [
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d', 'before_or_equal:to'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d'],
            'location_id' => ['nullable', 'integer'],
            'source' => ['nullable', Rule::in(['all', 'pos', 'channel'])],
            'rank_by' => ['nullable', Rule::in(['units', 'net_total'])],
        ])->validate();

        return [
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'location_id' => isset($data['location_id']) ? (int) $data['location_id'] : null,
            'source' => $data['source'] ?? 'all',
            'rank_by' => $data['rank_by'] ?? 'units',
        ];
    }

    /** @param array{from: ?string, to: ?string, location_id: ?int, source: string, rank_by: string} $filters
     * @return Builder<Sale>
     */
    public function salesQuery(User $user, array $filters): Builder
    {
        abort_unless($user->is_active && in_array($user->role, ['admin', 'manager', 'cashier'], true), 403);
        if ($filters['location_id'] !== null) {
            Location::where('tenant_id', $user->tenant_id)->findOrFail($filters['location_id']);
        }

        $query = Sale::where('tenant_id', $user->tenant_id)
            ->whereIn('status', ['completed', 'partially_returned', 'returned'])
            ->when($user->role === 'cashier', fn (Builder $query): Builder => $query->where('user_id', $user->id))
            ->when($filters['location_id'] !== null, fn (Builder $query): Builder => $query->where('location_id', $filters['location_id']))
            ->when($filters['source'] !== 'all', fn (Builder $query): Builder => $query->where('source', $filters['source']));

        if ($filters['from'] !== null) {
            $storageTimezone = config('app.timezone', 'UTC');
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], self::TIMEZONE)->setTimezone($storageTimezone);
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], self::TIMEZONE)->addDay()->setTimezone($storageTimezone);
            $query->where('created_at', '>=', $start->toDateTimeString())->where('created_at', '<', $end->toDateTimeString());
        }

        return $query;
    }

    /** @param array{from: ?string, to: ?string, location_id: ?int, source: string, rank_by: string} $filters
     * @return array<string, mixed>
     */
    public function report(User $user, array $filters): array
    {
        $summary = $this->emptyTotals() + ['returned_transactions' => 0, 'simulated_transactions' => 0];
        $hourly = array_map(fn (int $hour): array => ['hour' => $hour] + $this->emptyTotals(), range(0, 23));
        $products = [];
        $labels = [];
        $sales = $this->salesQuery($user, $filters)->select([
            'id', 'status', 'source', 'items', 'subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents', 'returned_cents', 'created_at',
        ])->lazyById(200);

        foreach ($sales as $sale) {
            $createdAt = CarbonImmutable::parse($sale->getRawOriginal('created_at'), config('app.timezone', 'UTC'));
            $hour = (int) $createdAt->setTimezone(self::TIMEZONE)->format('G');
            $timestamp = $createdAt->format('Y-m-d H:i:s.u');
            $receipt = $this->emptyTotals();
            $receipt['transactions'] = 1;
            $receipt['gross_total'] = (int) $sale->total_cents;
            $receipt['returned_total'] = $sale->status === 'returned' ? $receipt['gross_total'] : (int) $sale->returned_cents;
            $receipt['net_total'] = $receipt['gross_total'] - $receipt['returned_total'];
            $summary['returned_transactions'] += in_array($sale->status, ['partially_returned', 'returned'], true) ? 1 : 0;
            $summary['simulated_transactions'] += $sale->source === 'channel' ? 1 : 0;

            foreach ($this->allocator->saleItems($sale) as $item) {
                $productId = $item['product_id'];
                $quantity = $item['quantity'];
                $returned = (int) $item['returned_quantity'];
                $remaining = $this->allocator->refund($item, $quantity - $returned)['total_cents'];
                $line = [
                    'units_sold' => $quantity, 'units_returned' => $returned, 'net_units' => $quantity - $returned,
                    'gross_total' => (int) $item['total_cents'], 'returned_total' => $item['total_cents'] - $remaining, 'net_total' => $remaining,
                ];
                $products[$productId] ??= ['product_id' => $productId, 'name' => $item['name'], 'sku' => $item['sku']] + array_fill_keys(array_keys($line), 0);
                foreach ($line as $key => $value) {
                    $products[$productId][$key] += $value;
                }
                foreach (['units_sold', 'units_returned', 'net_units'] as $key) {
                    $receipt[$key] += $line[$key];
                }

                if (! isset($labels[$productId]) || [$timestamp, $sale->id] > $labels[$productId]) {
                    $products[$productId]['name'] = $item['name'];
                    $products[$productId]['sku'] = $item['sku'];
                    $labels[$productId] = [$timestamp, $sale->id];
                }
            }

            foreach ($receipt as $key => $value) {
                $summary[$key] += $value;
                $hourly[$hour][$key] += $value;
            }
        }

        $averageCents = $summary['transactions'] === 0 ? 0 : BigInteger::of($summary['net_total'])
            ->dividedBy($summary['transactions'], RoundingMode::HalfUp)->toInt();
        $summary['average_basket'] = $averageCents / 100;
        $peakTransactions = max(array_column($hourly, 'transactions'));
        $peakHours = $peakTransactions === 0 ? [] : array_column(array_filter($hourly, fn (array $hour): bool => $hour['transactions'] === $peakTransactions), 'hour');
        $ranked = array_values(array_filter($products, fn (array $product): bool => $product['net_units'] > 0));
        $primary = $filters['rank_by'] === 'units' ? 'net_units' : 'net_total';
        $secondary = $filters['rank_by'] === 'units' ? 'net_total' : 'net_units';
        usort($ranked, fn (array $a, array $b): int => ($b[$primary] <=> $a[$primary]) ?: ($b[$secondary] <=> $a[$secondary]) ?: ($a['product_id'] <=> $b['product_id']));

        return [
            'filters' => $filters + ['timezone' => self::TIMEZONE, 'scope' => $user->role === 'cashier' ? 'own_sales' : 'business'],
            'summary' => $this->moneyTotals($summary),
            'hourly' => array_map(fn (array $hour): array => $this->moneyTotals($hour), $hourly),
            'peak_hours' => $peakHours,
            'best_sellers' => array_map(fn (array $product): array => $this->moneyTotals($product), array_slice($ranked, 0, 10)),
            'product_count' => count($products),
            'ranked_product_count' => count($ranked),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array{transactions: int, gross_total: int, returned_total: int, net_total: int, units_sold: int, units_returned: int, net_units: int} */
    private function emptyTotals(): array
    {
        return ['transactions' => 0, 'gross_total' => 0, 'returned_total' => 0, 'net_total' => 0, 'units_sold' => 0, 'units_returned' => 0, 'net_units' => 0];
    }

    /** @param array<string, mixed> $totals
     * @return array<string, mixed>
     */
    private function moneyTotals(array $totals): array
    {
        foreach (['gross_total', 'returned_total', 'net_total'] as $key) {
            $totals[$key] /= 100;
        }

        return $totals;
    }
}
