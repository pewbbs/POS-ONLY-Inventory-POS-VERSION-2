<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\FinancialReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialReportController extends Controller
{
    public function show(Request $request, FinancialReportService $reports): JsonResponse
    {
        return response()->json($reports->report($request->user(), $reports->validatedFilters($request)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, FinancialReportService $reports): StreamedResponse
    {
        $user = User::where('tenant_id', $request->user()->tenant_id)->findOrFail($request->user()->id);
        abort_unless($user->is_active && $user->role === 'admin', 403, 'Administrator access is required to export financial reports.');
        $report = $reports->report($user, $reports->validatedFilters($request), true);

        return response()->streamDownload(function () use ($report): void {
            $handle = fopen('php://output', 'w');
            $write = fn (array $row) => fputcsv($handle, $row, ',', '"', '');
            $write(['POSONLY Financial Report']);
            $write(['Filters']);
            $write(['Field', 'Value']);
            foreach ($report['filters'] as $key => $value) {
                $write([$key, $value === null ? 'All' : $this->csvText((string) $value)]);
            }
            $write(['generated_at', $report['generated_at']]);
            $write([]);
            $write(['Summary']);
            $write(['Metric', 'Count']);
            foreach ($report['summary'] as $key => $value) {
                $write([$key, $value]);
            }
            $write([]);
            $write(['Sales, credit notes and net']);
            $write(['Type', 'Subtotal PHP', 'Discount PHP', 'Tax PHP', 'Total PHP', 'Revenue ex tax PHP']);
            foreach (['sales', 'refunds', 'net'] as $key) {
                $write([$key, ...array_map(fn (int $cents): string => $this->formatCents($cents), array_values($report[$key]))]);
            }
            $write([]);
            $write(['Cost snapshot estimates']);
            $write(['Metric', 'Value']);
            foreach ($report['costs'] as $key => $value) {
                $money = in_array($key, ['sales_cost', 'returned_cost', 'net_cost', 'estimated_gross_margin'], true);
                $write([$key, $value === null ? 'Unavailable' : ($money ? $this->formatCents($value) : $value)]);
            }
            $write([]);
            $write(['Coverage']);
            $write(['Metric', 'Value']);
            foreach ($report['coverage'] as $key => $value) {
                $write([$key, str_ends_with($key, '_total') ? $this->formatCents($value) : $value]);
            }
            $write([]);
            $write(['Daily']);
            $write(['Date', 'Sales count', 'Refund count', 'Sales total PHP', 'Refund total PHP', 'Net revenue ex tax PHP', 'Net tax PHP', 'Net total PHP']);
            foreach ($report['daily'] as $row) {
                $write([$row['date'], ...$this->eventCells($row)]);
            }
            $write([]);
            $write(['Locations']);
            $write(['Location ID', 'Name', 'Sales count', 'Refund count', 'Sales total PHP', 'Refund total PHP', 'Net revenue ex tax PHP', 'Net tax PHP', 'Net total PHP']);
            foreach ($report['locations'] as $row) {
                $write([$row['location_id'], $this->csvText($row['name']), ...$this->eventCells($row)]);
            }
            $write([]);
            $write(['Sources']);
            $write(['Source', 'Simulated', 'Sales count', 'Refund count', 'Sales total PHP', 'Refund total PHP', 'Net revenue ex tax PHP', 'Net tax PHP', 'Net total PHP']);
            foreach ($report['sources'] as $row) {
                $write([$this->csvText($row['source']), $row['simulated'] ? 'yes' : 'no', ...$this->eventCells($row)]);
            }
            $write([]);
            $write(['Tax rates']);
            $write(['Tax percent', 'Sales revenue ex tax PHP', 'Refund revenue ex tax PHP', 'Net revenue ex tax PHP', 'Sales tax PHP', 'Refund tax PHP', 'Net tax PHP']);
            foreach ($report['tax_rates'] as $row) {
                $write([number_format($row['tax_percent'], 2, '.', ''), ...array_map(fn (string $key): string => $this->formatCents($row[$key]), ['sales_revenue_ex_tax', 'refund_revenue_ex_tax', 'net_revenue_ex_tax', 'sales_tax', 'refund_tax', 'net_tax'])]);
            }
            $write([]);
            $write(['Recorded sale tenders']);
            $write(['Method', 'Source', 'Simulated', 'Transactions', 'Tendered PHP', 'Change PHP', 'Accepted PHP']);
            foreach ($report['tenders'] as $row) {
                $write([$this->csvText($row['method']), $this->csvText($row['source']), $row['simulated'] ? 'yes' : 'no', $row['transactions'], $this->formatCents($row['tendered']), $this->formatCents($row['change']), $this->formatCents($row['accepted'])]);
            }
            $write([]);
            $write(['Notes', 'Cost snapshots give a gross margin estimate; operating expenses and settled refund payments are not recorded.']);
            $write(['Refund tender destinations', 'Unallocated; sale tenders do not represent net settlements after refunds.']);
            fclose($handle);
        }, 'posonly-financial-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store']);
    }

    private function eventCells(array $row): array
    {
        return [$row['sales_count'], $row['refund_count'], ...array_map(fn (string $key): string => $this->formatCents($row[$key]), ['sales_total', 'refund_total', 'net_revenue_ex_tax', 'net_tax', 'net_total'])];
    }

    private function formatCents(int $cents): string
    {
        $absolute = abs($cents);

        return ($cents < 0 ? '-' : '').intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private function csvText(string $text): string
    {
        return preg_match('/^\s*[=+\-@]/u', $text) ? "'".$text : $text;
    }
}
