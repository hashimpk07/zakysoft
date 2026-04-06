<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SalesClientOrderSummaryExportJob extends QueueExport
{
    protected int $chunk = 1000;
    protected string $file_name = 'client_shops_statistics';

    /**
     * Execute the job.
     */
    public function data(): array
    {
        $data = [];
        $client_datas = $this->getReport();

        foreach ($client_datas as $client_data) {
            // success rate
            if ($client_data->total_orders > 0) {
                $successRate = number_format(($client_data->delivered / $client_data->total_orders) * 100, 2) . '%';
            } else {
                $successRate = 'N/A';
            }

            // build row
            $data[] = [
                $client_data->client_name,
                $client_data->total_branches,
                $client_data->active_branches,
                $client_data->inactive_branches,
                $client_data->serving_regions_count,
                $client_data->total_orders,
                $client_data->delivered,
                $client_data->total_orders - $client_data->delivered, // failed = total - delivered
                $successRate,
                $client_data->client_weights_count,
                $client_data->client_status,
            ];
        }

        return $data;
    }

    public function getReport()
    {
        $request = $this->export->filters;

        $startDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'])->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'])->endOfDay()
            : Carbon::now()->endOfDay();

        $clientId = $request['client'] ?? null;

        $chunk = $this->chunk ?? 100;
        $pageDone = $this->export->page_done ?? 0;

        $query = DB::table('users as u')
            ->join('clients as c', 'c.user_id', '=', 'u.id')
            // Branch counts
            ->leftJoin(DB::raw("
                (
                    SELECT 
                        client_id,
                        COUNT(id) AS total_branches,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_branches,
                        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_branches
                    FROM client_shops
                    GROUP BY client_id
                ) b
            "), 'b.client_id', '=', 'c.id')
            // Order counts with date filter
            ->leftJoin(DB::raw("
                (
                    SELECT 
                        client_id,
                        COUNT(id) AS total_orders,
                        SUM(CASE WHEN status_id = 10 THEN 1 ELSE 0 END) AS total_deliveries
                    FROM orders
                    WHERE created_at BETWEEN '{$startDate}' AND '{$endDate}'
                    GROUP BY client_id
                ) o
            "), 'o.client_id', '=', 'c.id')
            // Serving regions
            ->leftJoin(DB::raw("
                (
                    SELECT 
                        orp.client_id,
                        COUNT(DISTINCT shop_region.quadrant_id) AS serving_regions_count
                    FROM order_reports orp
                    LEFT JOIN client_shops ON client_shops.id = orp.shop_id
                    LEFT JOIN zones AS shop_zone ON shop_zone.id = client_shops.zone_id
                    LEFT JOIN regions AS shop_region ON shop_region.id = shop_zone.region_id
                    WHERE orp.final_status_at BETWEEN '{$startDate}' AND '{$endDate}'
                    GROUP BY orp.client_id
                ) r
            "), 'r.client_id', '=', 'c.id')
            // Delivered orders within date range (client_weights_count)
            ->leftJoin(DB::raw("
                (
                    SELECT 
                        orp.client_id,
                        COUNT(*) AS client_weights_count
                    FROM order_reports orp
                    WHERE orp.status_id = 10
                    AND orp.final_status_at BETWEEN '{$startDate}' AND '{$endDate}'
                    GROUP BY orp.client_id
                ) w
            "), 'w.client_id', '=', 'c.id')
            // Global deliveries
            ->leftJoin(DB::raw("
                (
                    SELECT 
                        COUNT(*) AS global_deliveries
                    FROM orders
                    WHERE status_id = 10
                    AND created_at BETWEEN '{$startDate}' AND '{$endDate}'
                ) g
            "), DB::raw("1"), '=', DB::raw("1"))
            ->select([
                'u.id as user_id',
                'c.id as client_id',
                'u.name as client_name',
                'c.status as client_status',
                DB::raw("COALESCE(b.total_branches, 0) as total_branches"),
                DB::raw("COALESCE(b.active_branches, 0) as active_branches"),
                DB::raw("COALESCE(b.inactive_branches, 0) as inactive_branches"),
                DB::raw("COALESCE(o.total_orders, 0) as total_orders"),
                DB::raw("COALESCE(o.total_deliveries, 0) as delivered"),
                DB::raw("COALESCE(r.serving_regions_count, 0) as serving_regions_count"),
                DB::raw("COALESCE(w.client_weights_count, 0) as client_weights_count"),
                DB::raw("COALESCE(g.global_deliveries, 0) as global_deliveries"),
            ])
            ->when($clientId, function ($q) use ($clientId) {
                $q->where('c.id', $clientId);
            })
            ->orderBy('c.id');

        // ✅ fetch batch
        return $query
            ->limit($chunk)
            ->offset($pageDone * $chunk)
            ->get();
    }

    public function headers(): array
    {
        return [
            "Client Name",
            "Total Branch",
            "Active Branch",
            "Inactive Branch",
            "Service Regions",
            "Total Orders",
            "Delivered Orders",
            "Failed Orders",
            "Success Rate",
            "Client Weight",
            "Status",
        ];
    }
    public function count(): int
    {
        $request = $this->export->filters;

        $startDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'])->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'])->endOfDay()
            : Carbon::now()->endOfDay();

        $clientId = $request['client'] ?? null;

        $query = DB::table('clients as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('orders as o', function ($join) use ($startDate, $endDate) {
            $join->on('o.client_id', '=', 'c.id')
                ->whereBetween('o.created_at', [$startDate, $endDate]);
            })
            ->when($clientId, function ($q) use ($clientId) {
                $q->where('c.id', $clientId);
            });

        return $query->distinct('c.id')->count('c.id');
    }

}
