<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\OrderReport;
use App\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesOldNewOrderExportJob extends QueueExport
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
            Log::error('ClientOrderNewOldExportJob failed', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Collect monthly stats (total, delivered, failed, new/old client orders, success rate)
     */
    public function getReport(): array
    {
        if ($this->reportCache !== null) {
            return $this->reportCache;
        }

        $filters  = $this->export->filters ?? [];
        $regionId = $filters['region'] ?? null;

        // Reference date = current month OR to_date - 1 month (exclude current)
        $referenceDate = !empty($filters['to_date'])
            ? Carbon::parse($filters['to_date'])->startOfMonth()->subMonth()
            : Carbon::now()->startOfMonth()->subMonth();

        // Generate previous 12 months excluding current
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[] = $referenceDate->copy()->subMonths($i)->format('Y-m');
        }
        $months = array_reverse($months);

        $startDate = Carbon::parse($months[0] . '-01')->startOfMonth();
        $endDate   = Carbon::parse(end($months) . '-01')->endOfMonth();

        // --- Optimized aggregated query ---
        $aggregated = OrderReport::query()
            ->select(
                DB::raw("DATE_FORMAT(order_reports.final_status_at, '%Y-%m') as order_month"),
                DB::raw("COUNT(*) as total_orders"),
                DB::raw("SUM(CASE WHEN order_reports.status_id = " . OrderStatus::DELIVERED . " THEN 1 ELSE 0 END) as delivered_orders"),
                DB::raw("SUM(CASE WHEN clients.created_at BETWEEN DATE_FORMAT(order_reports.final_status_at, '%Y-%m-01') AND LAST_DAY(order_reports.final_status_at) THEN 1 ELSE 0 END) as new_client_orders"),
                DB::raw("SUM(CASE WHEN clients.created_at < DATE_FORMAT(order_reports.final_status_at, '%Y-%m-01') THEN 1 ELSE 0 END) as old_client_orders")
            )
            ->leftJoin('clients', 'clients.id', '=', 'order_reports.client_id')
            ->leftJoin('client_shops', 'client_shops.id', '=', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', '=', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', '=', 'shop_zone.region_id')
            ->when($regionId, fn($q) => $q->where('shop_region.quadrant_id', $regionId))
            ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
            ->whereIn('order_reports.status_id', OrderStatus::FINISHED)
            ->groupBy('order_month')
            ->orderBy('order_month')
            ->get()
            ->keyBy('order_month');

        $rows = [];

        foreach ($months as $month) {
            $label = Carbon::parse($month . '-01')->format('M-y');
            $row   = $aggregated[$month] ?? null;

            $total     = $row->total_orders ?? 0;
            $delivered = $row->delivered_orders ?? 0;
            $failed    = max(0, $total - $delivered);
            $newOrders = $row->new_client_orders ?? 0;
            $oldOrders = $row->old_client_orders ?? 0;
            $success   = $total > 0 ? round(($delivered / $total) * 100, 2) : 0;

            $rows[] = [
                'Month'             => $label,
                'Total Orders'      => $total,
                'Delivered Orders'  => $delivered,
                'Failed Orders'     => $failed,
                'New Client Orders' => $newOrders,
                'Old Client Orders' => $oldOrders,
                'Success Rate %'    => $success,
            ];
        }

        $this->reportCache = $rows;
        return $this->reportCache;
    }
    public function headers(): array
    {
        return [
            'Month',
            'Total Orders',
            'Delivered Orders',
            'Failed Orders',
            'New Client Orders',
            'Old Client Orders',
            'Success Rate %',
        ];
    }
    public function count(): int
    {
        $rows = $this->getReport();
        return count($rows);
    }
}