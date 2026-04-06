<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\OrderReport;
use App\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;


class SalesOrderAnalyticsExportJob extends QueueExport
{
    protected int $chunk = 1000;
    protected string $file_name = 'sales_order_analytics';

    /** @var array|null */
    protected ?array $reportCache = null;

    /**
     * Build export data (chunked)
     */
    public function data(): array
    {
        try {
            $rows = $this->getReport();

            $offset = $this->export->page_done * $this->chunk;
            $chunk  = array_slice($rows, $offset, $this->chunk);

            Log::channel('auto_assigning')->info('final processed rows', ['rows' => $chunk]);

            return $chunk;
        } catch (\Throwable $e) {
            Log::error('ClientOrderSuccessRateExportJob failed', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Collect monthly order stats (total, delivered, failed, success rate)
     */
    public function getReport(): array
    {
        if ($this->reportCache !== null) {
            return $this->reportCache;
        }

        $filters  = $this->export->filters ?? [];
        $regionId = $filters['region'] ?? null;
        $clientId = $filters['client'] ?? null;

        // Reference date = current month OR to_date - 1 month (exclude current)
        $referenceDate = !empty($filters['to_date'])
            ? Carbon::parse($filters['to_date'])->startOfMonth()->subMonth()
            : Carbon::now()->startOfMonth()->subMonth();

        // Generate previous 12 months excluding current month
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[] = $referenceDate->copy()->subMonths($i)->format('Y-m');
        }
        $months = array_reverse($months);

        $startDate = Carbon::parse($months[0] . '-01')->startOfMonth();
        $endDate   = Carbon::parse(end($months) . '-01')->endOfMonth();

        // Aggregated orders query
        $orders = OrderReport::query()
            ->select(
                DB::raw("DATE_FORMAT(order_reports.created_at, '%Y-%m') as order_month"),
                DB::raw("COUNT(*) as total_orders"),
                DB::raw("SUM(CASE WHEN order_reports.status_id = " . OrderStatus::DELIVERED . " THEN 1 ELSE 0 END) as delivered_orders")
            )
            ->leftJoin('client_shops', 'client_shops.id', '=', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', '=', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', '=', 'shop_zone.region_id')
            ->when($regionId, fn($q) => $q->where('shop_region.quadrant_id', $regionId))
            ->when($clientId, fn($q) => $q->where('order_reports.client_id', $clientId))
            ->whereBetween('order_reports.created_at', [$startDate, $endDate])
            ->groupBy('order_month')
            ->orderBy('order_month')
            ->get()
            ->keyBy('order_month');

        $rows = [];

        foreach ($months as $month) {
            $label = Carbon::parse($month . '-01')->format('M-y');
            $row   = $orders[$month] ?? null;

            $total     = $row->total_orders ?? 0;
            $delivered = $row->delivered_orders ?? 0;
            $failed    = max(0, $total - $delivered);
            $success   = $total > 0 ? round(($delivered / $total) * 100, 2) : 0;

            $rows[] = [
                'Month'            => $label,
                'Total Orders'     => $total,
                'Delivered Orders' => $delivered,
                'Failed Orders'    => $failed,
                'Success Rate %'   => $success,
            ];
        }

        $this->reportCache = $rows;
        return $this->reportCache;
    }

    public function headers(): array
    {
        return ['Month', 'Total Orders', 'Delivered Orders', 'Failed Orders', 'Success Rate %'];
    }

    public function count(): int
    {
        $rows = $this->getReport();
        return count($rows);
    }
}
