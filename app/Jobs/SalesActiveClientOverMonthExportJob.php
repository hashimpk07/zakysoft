<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\OrderReport;

class SalesActiveClientOverMonthExportJob extends QueueExport
{
    protected int $chunk = 1000;
    protected string $file_name = 'client_shops_statistics';

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
            Log::error('ClientOrderAnalyticsExportJob failed', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Collect client orders over last 12 months
     */
    public function getReport(): array
    {
        if ($this->reportCache !== null) {
            return $this->reportCache;
        }

        $filters  = $this->export->filters ?? [];
        $regionId = $filters['region'] ?? null;

        // Reference date = given to_date OR current month
        $referenceDate = !empty($filters['to_date'])
            ? Carbon::parse($filters['to_date'])->startOfMonth()
            : Carbon::now()->startOfMonth();

        // Generate last 12 months (excluding reference month)
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[] = $referenceDate->copy()->subMonths($i + 1)->format('Y-m');
        }
        $months = array_reverse($months);

        $startDate = Carbon::parse($months[0] . '-01')->startOfMonth();
        $endDate   = Carbon::parse(end($months) . '-01')->endOfMonth();

        // Aggregate query
        $aggregated = OrderReport::query()
            ->select(
                DB::raw("DATE_FORMAT(order_reports.final_status_at, '%Y-%m') as order_month"),
                DB::raw("COUNT(DISTINCT CASE 
                        WHEN clients.created_at >= DATE_FORMAT(order_reports.final_status_at, '%Y-%m-01') 
                         AND clients.created_at <= LAST_DAY(order_reports.final_status_at) 
                        THEN clients.id END) as new_client_count"),
                DB::raw("COUNT(DISTINCT CASE 
                        WHEN clients.created_at < DATE_FORMAT(order_reports.final_status_at, '%Y-%m-01') 
                        THEN clients.id END) as existing_client_count")
            )
            ->leftJoin('clients', 'clients.id', '=', 'order_reports.client_id')
            ->leftJoin('client_shops', 'client_shops.id', '=', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', '=', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', '=', 'shop_zone.region_id')
            ->when($regionId, fn($q) => $q->where('shop_region.quadrant_id', $regionId))
            ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
            ->BelongsToMe()
            ->groupBy('order_month')
            ->orderBy('order_month', 'asc')
            ->get()
            ->keyBy('order_month');

        $rows = [];

        foreach ($months as $month) {
            $label = Carbon::parse($month . '-01')->format('M-y');
            $row   = $aggregated[$month] ?? null;

            $new   = $row->new_client_count ?? 0;
            $old   = $row->existing_client_count ?? 0;
            $total = $new + $old;

            $rows[] = [
                'Month'                => $label,
                'New Client Orders'    => $new,
                'Existing Client Orders' => $old,
                'Total Client Orders'  => $total,
            ];
        }

        $this->reportCache = $rows;
        return $this->reportCache;
    }

    public function headers(): array
    {
        return ['Month', 'New Client Orders', 'Existing Client Orders', 'Total Client Orders'];
    }

    public function count(): int
    {
        $rows = $this->getReport();
        return count($rows);
    }
}
