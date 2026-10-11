<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FinancialReportService
{
    private const TIMEZONE = 'Asia/Manila';

    private const COMPONENTS = ['subtotal', 'discount', 'tax', 'total', 'revenue_ex_tax'];

    public function __construct(private SalesAnalyticsService $analytics, private SaleRefundAllocator $allocator) {}

    /** @return array{from: ?string, to: ?string, location_id: ?int, source: string, basis: string} */
    public function validatedFilters(Request $request): array
    {
        $filters = $this->analytics->validatedFilters($request);
        unset($filters['rank_by']);
        $data = Validator::make($request->query(), ['basis' => ['nullable', Rule::in(['activity', 'sale_cohort'])]])->validate();

        return $filters + ['basis' => $data['basis'] ?? 'activity'];
    }

    /** The cents representation is used internally by CSV export to preserve exact decimals.
     * @param  array{from: ?string, to: ?string, location_id: ?int, source: string, basis: string}  $filters
     * @return array<string, mixed>
     */
    public function report(User $user, array $filters, bool $inCents = false): array
    {
        $actor = User::where('tenant_id', $user->tenant_id)->findOrFail($user->id);
        abort_unless($actor->is_active && in_array($actor->role, ['admin', 'manager'], true), 403, 'Manager or administrator access is required for financial reports.');
        $queryFilters = $filters + ['rank_by' => 'units'];
        $salesQuery = $this->analytics->salesQuery($actor, $queryFilters);
        $baseQuery = $this->analytics->salesQuery($actor, array_replace($queryFilters, ['from' => null, 'to' => null]));
        $state = [
            'summary' => ['sales_count' => 0, 'refund_count' => 0, 'legacy_refund_count' => 0, 'simulated_sales_count' => 0, 'simulated_refund_count' => 0],
            'sales' => $this->emptyComponents(), 'refunds' => $this->emptyComponents(),
            'costs' => ['sales_cost' => 0, 'returned_cost' => 0, 'net_cost' => 0, 'estimated_gross_margin' => null, 'missing_sales_units' => 0, 'missing_return_units' => 0, 'snapshot_sales_units' => 0, 'snapshot_return_units' => 0],
            'daily' => [], 'locations' => [], 'sources' => [], 'tax_rates' => [], 'tenders' => [],
            'coverage' => ['undated_legacy_refunds' => 0, 'undated_legacy_refund_total' => 0, 'legacy_dated_refunds' => 0, 'legacy_dated_refund_total' => 0, 'unallocated_refund_total' => 0, 'unreconciled_tender_sales' => 0, 'unreconciled_tender_total' => 0],
        ];
        foreach ((clone $salesQuery)->lazyById(200) as $sale) {
            $components = $this->components($sale);
            $date = $this->date($sale->getRawOriginal('created_at'));
            $state['summary']['sales_count']++;
            $state['summary']['simulated_sales_count'] += $sale->source === 'channel' ? 1 : 0;
            $this->addComponents($state['sales'], $components);
            $this->groupEvent($state, $sale, $date, $components, false);
            $this->addCosts($state['costs'], $sale->items, false);
            $this->addTenders($state, $sale);
        }

        $refundQuery = SaleReturn::where('tenant_id', $actor->tenant_id)
            ->whereIn('sale_id', (clone ($filters['basis'] === 'sale_cohort' ? $salesQuery : $baseQuery))->select('id'))
            ->with('sale');
        if ($filters['basis'] === 'activity') {
            $this->dateBounds($refundQuery, $filters, 'created_at');
        }
        foreach ($refundQuery->lazyById(200) as $refund) {
            $sale = $refund->sale;
            $date = $this->date($filters['basis'] === 'sale_cohort' ? $sale->getRawOriginal('created_at') : $refund->getRawOriginal('created_at'));
            $this->addRefund($state, $sale, $this->components($refund), $refund->items, $date, false);
        }

        $legacyQuery = (clone ($filters['basis'] === 'sale_cohort' ? $salesQuery : $baseQuery))->whereIn('status', ['returned', 'partially_returned']);
        $legacyQuery->chunkById(200, function ($sales) use ($actor, $filters, &$state): void {
            $actual = [];
            $notes = SaleReturn::where('tenant_id', $actor->tenant_id)->whereIn('sale_id', $sales->modelKeys())->select(['id', 'sale_id', 'items', 'subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents']);
            foreach ($notes->lazyById(200) as $note) {
                $actual[$note->sale_id] ??= ['components' => $this->emptyComponents(), 'quantities' => []];
                $this->addComponents($actual[$note->sale_id]['components'], $this->components($note));
                foreach ($note->items as $item) {
                    $productId = (int) $item['product_id'];
                    $actual[$note->sale_id]['quantities'][$productId] = ($actual[$note->sale_id]['quantities'][$productId] ?? 0) + (int) $item['quantity'];
                }
            }
            foreach ($sales as $sale) {
                $legacy = $this->legacyRemainder($sale, $actual[$sale->id] ?? ['components' => $this->emptyComponents(), 'quantities' => []]);
                if ($legacy === null) {
                    continue;
                }
                $returnedAt = $sale->getRawOriginal('returned_at');
                if ($returnedAt === null) {
                    $state['coverage']['undated_legacy_refunds']++;
                    $state['coverage']['undated_legacy_refund_total'] += $legacy['components']['total'];
                    if ($filters['basis'] === 'activity') {
                        continue;
                    }
                } elseif ($filters['basis'] === 'activity' && ! $this->withinDates($returnedAt, $filters)) {
                    continue;
                } else {
                    $state['coverage']['legacy_dated_refunds']++;
                    $state['coverage']['legacy_dated_refund_total'] += $legacy['components']['total'];
                }
                $date = $this->date($filters['basis'] === 'sale_cohort' ? $sale->getRawOriginal('created_at') : $returnedAt);
                $this->addRefund($state, $sale, $legacy['components'], $legacy['items'], $date, true);
            }
        });

        $state['net'] = [];
        foreach (self::COMPONENTS as $key) {
            $state['net'][$key] = $state['sales'][$key] - $state['refunds'][$key];
        }
        $state['costs']['net_cost'] = $state['costs']['sales_cost'] - $state['costs']['returned_cost'];
        if ($state['costs']['missing_sales_units'] === 0 && $state['costs']['missing_return_units'] === 0) {
            $state['costs']['estimated_gross_margin'] = $state['net']['revenue_ex_tax'] - $state['costs']['net_cost'];
        }
        $state['coverage']['unallocated_refund_total'] = $state['refunds']['total'];
        $locationNames = Location::where('tenant_id', $actor->tenant_id)->pluck('name', 'id');
        foreach ($state['locations'] as $id => &$row) {
            $row = ['location_id' => (int) $id, 'name' => $locationNames->get($id, 'Location')] + $row;
        }
        unset($row);
        ksort($state['daily']);
        ksort($state['locations'], SORT_NUMERIC);
        ksort($state['sources']);
        ksort($state['tax_rates'], SORT_NUMERIC);
        ksort($state['tenders']);
        foreach (['daily', 'locations', 'sources', 'tax_rates', 'tenders'] as $key) {
            $state[$key] = array_values($state[$key]);
        }

        if (! $inCents) {
            foreach (['sales', 'refunds', 'net'] as $key) {
                $state[$key] = $this->money($state[$key], self::COMPONENTS);
            }
            $state['costs'] = $this->money($state['costs'], ['sales_cost', 'returned_cost', 'net_cost', 'estimated_gross_margin']);
            $state['coverage'] = $this->money($state['coverage'], ['undated_legacy_refund_total', 'legacy_dated_refund_total', 'unallocated_refund_total', 'unreconciled_tender_total']);
            foreach (['daily', 'locations', 'sources'] as $key) {
                $state[$key] = array_map(fn (array $row): array => $this->money($row, ['sales_total', 'refund_total', 'net_revenue_ex_tax', 'net_tax', 'net_total']), $state[$key]);
            }
            $state['tax_rates'] = array_map(fn (array $row): array => $this->money($row, ['sales_revenue_ex_tax', 'refund_revenue_ex_tax', 'net_revenue_ex_tax', 'sales_tax', 'refund_tax', 'net_tax']), $state['tax_rates']);
            $state['tenders'] = array_map(fn (array $row): array => $this->money($row, ['tendered', 'change', 'accepted']), $state['tenders']);
        }

        return ['filters' => $filters + ['timezone' => self::TIMEZONE, 'scope' => 'business']] + $state + ['generated_at' => now()->toIso8601String()];
    }

    private function date(string $raw): string
    {
        return CarbonImmutable::parse($raw, config('app.timezone', 'UTC'))->setTimezone(self::TIMEZONE)->toDateString();
    }

    private function dateBounds(Builder $query, array $filters, string $column): void
    {
        if ($filters['from'] === null) {
            return;
        }
        $timezone = config('app.timezone', 'UTC');
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], self::TIMEZONE)->setTimezone($timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], self::TIMEZONE)->addDay()->setTimezone($timezone);
        $query->where($column, '>=', $start->toDateTimeString())->where($column, '<', $end->toDateTimeString());
    }

    private function withinDates(string $raw, array $filters): bool
    {
        $date = $this->date($raw);

        return $filters['from'] === null || ($date >= $filters['from'] && $date <= $filters['to']);
    }

    private function emptyComponents(): array
    {
        return array_fill_keys(self::COMPONENTS, 0);
    }

    private function components(Sale|SaleReturn $record): array
    {
        $result = [];
        foreach (['subtotal', 'discount', 'tax', 'total'] as $key) {
            $result[$key] = (int) $record->{$key.'_cents'};
        }
        $result['revenue_ex_tax'] = $result['subtotal'] - $result['discount'];

        return $result;
    }

    private function addComponents(array &$target, array $components): void
    {
        foreach (self::COMPONENTS as $key) {
            $target[$key] += $components[$key];
        }
    }

    private function addCosts(array &$costs, array $items, bool $refund, ?array $originalItems = null): void
    {
        $original = $originalItems === null ? null : array_column($originalItems, null, 'product_id');
        foreach ($items as $item) {
            $quantity = (int) $item['quantity'];
            $costItem = $original === null ? $item : ($original[$item['product_id']] ?? []);
            if (isset($costItem['unit_cost_cents'])) {
                $costs[$refund ? 'returned_cost' : 'sales_cost'] += (int) $costItem['unit_cost_cents'] * $quantity;
                $costs[$refund ? 'snapshot_return_units' : 'snapshot_sales_units'] += $quantity;
            } else {
                $costs[$refund ? 'missing_return_units' : 'missing_sales_units'] += $quantity;
            }
        }
    }

    private function groupEvent(array &$state, Sale $sale, string $date, array $components, bool $refund): void
    {
        $source = $sale->source ?? 'pos';
        foreach (['daily' => $date, 'locations' => $sale->location_id, 'sources' => $source] as $group => $key) {
            $state[$group][$key] ??= [
                'sales_count' => 0, 'refund_count' => 0, 'sales_total' => 0, 'refund_total' => 0, 'net_revenue_ex_tax' => 0, 'net_tax' => 0, 'net_total' => 0,
            ];
            $row = &$state[$group][$key];
            $row[$refund ? 'refund_count' : 'sales_count']++;
            $row[$refund ? 'refund_total' : 'sales_total'] += $components['total'];
            $sign = $refund ? -1 : 1;
            $row['net_revenue_ex_tax'] += $sign * $components['revenue_ex_tax'];
            $row['net_tax'] += $sign * $components['tax'];
            $row['net_total'] += $sign * $components['total'];
            unset($row);
        }
        $state['daily'][$date]['date'] = $date;
        $state['sources'][$source]['source'] = $source;
        $state['sources'][$source]['simulated'] = $source === 'channel';
        $basisPoints = (int) round((float) $sale->tax_percent * 100);
        $state['tax_rates'][$basisPoints] ??= ['tax_percent' => $basisPoints / 100, 'sales_revenue_ex_tax' => 0, 'refund_revenue_ex_tax' => 0, 'net_revenue_ex_tax' => 0, 'sales_tax' => 0, 'refund_tax' => 0, 'net_tax' => 0];
        $rate = &$state['tax_rates'][$basisPoints];
        $rate[$refund ? 'refund_revenue_ex_tax' : 'sales_revenue_ex_tax'] += $components['revenue_ex_tax'];
        $rate[$refund ? 'refund_tax' : 'sales_tax'] += $components['tax'];
        $rate['net_revenue_ex_tax'] += ($refund ? -1 : 1) * $components['revenue_ex_tax'];
        $rate['net_tax'] += ($refund ? -1 : 1) * $components['tax'];
        unset($rate);
    }

    private function addRefund(array &$state, Sale $sale, array $components, array $items, string $date, bool $legacy): void
    {
        $state['summary']['refund_count']++;
        $state['summary']['legacy_refund_count'] += $legacy ? 1 : 0;
        $state['summary']['simulated_refund_count'] += $sale->source === 'channel' ? 1 : 0;
        $this->addComponents($state['refunds'], $components);
        $this->addCosts($state['costs'], $items, true, $sale->items);
        $this->groupEvent($state, $sale, $date, $components, true);
    }

    private function addTenders(array &$state, Sale $sale): void
    {
        $grouped = [];
        foreach ($sale->payments ?? [] as $payment) {
            $method = $payment['method'];
            $source = $sale->source ?? 'pos';
            $simulated = $source === 'channel' || (bool) ($payment['simulated'] ?? false);
            $key = $method.'|'.$source.'|'.($simulated ? '1' : '0');
            $grouped[$key] ??= ['method' => $method, 'source' => $source, 'simulated' => $simulated, 'transactions' => 1, 'tendered' => 0, 'change' => 0, 'accepted' => 0];
            $grouped[$key]['tendered'] += (int) $payment['amount_cents'];
        }
        $change = (int) $sale->change_cents;
        $accepted = 0;
        ksort($grouped);
        foreach ($grouped as $key => &$row) {
            if ($row['method'] === 'cash') {
                $row['change'] = $change;
                $change = 0;
            }
            $row['accepted'] = $row['tendered'] - $row['change'];
            $accepted += $row['accepted'];
            $state['tenders'][$key] ??= array_replace($row, ['transactions' => 0, 'tendered' => 0, 'change' => 0, 'accepted' => 0]);
            foreach (['transactions', 'tendered', 'change', 'accepted'] as $field) {
                $state['tenders'][$key][$field] += $row[$field];
            }
        }
        unset($row);
        if ($accepted !== (int) $sale->total_cents) {
            $state['coverage']['unreconciled_tender_sales']++;
            $state['coverage']['unreconciled_tender_total'] += $sale->total_cents - $accepted;
        }
    }

    private function legacyRemainder(Sale $sale, array $actual): ?array
    {
        $desired = $this->emptyComponents();
        $items = [];
        foreach ($this->allocator->saleItems($sale) as $item) {
            $returnedQuantity = (int) $item['returned_quantity'];
            $original = array_replace($item, ['returned_quantity' => 0]);
            $credit = $this->allocator->refund($original, $returnedQuantity);
            $this->addComponents($desired, [
                'subtotal' => $credit['subtotal_cents'], 'discount' => $credit['discount_cents'],
                'tax' => $credit['tax_cents'], 'total' => $credit['total_cents'], 'revenue_ex_tax' => $credit['subtotal_cents'] - $credit['discount_cents'],
            ]);
            $missing = $returnedQuantity - ($actual['quantities'][$item['product_id']] ?? 0);
            if ($missing > 0) {
                $items[] = ['product_id' => $item['product_id'], 'quantity' => $missing];
            }
        }
        if ($sale->status === 'returned') {
            $desired = $this->components($sale);
        }
        $components = [];
        foreach (self::COMPONENTS as $key) {
            $components[$key] = $desired[$key] - $actual['components'][$key];
        }
        if ($components['total'] <= 0 && $items === []) {
            return null;
        }

        return ['components' => $components, 'items' => $items];
    }

    private function money(array $row, array $keys): array
    {
        foreach ($keys as $key) {
            if ($row[$key] !== null) {
                $row[$key] /= 100;
            }
        }

        return $row;
    }
}
