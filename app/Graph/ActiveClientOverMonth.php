<?php
namespace App\Graph;

use App\Client;
use App\OrderReport;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ActiveClientOverMonth implements Graph
{
    public function data($request = null)
    {
        $colors = ['#36a2eb', '#2f01d6ff', '#1f6f70'];

        $regionId = $request->input('region');

        $referenceDate = $request->filled('to_date')
            ? Carbon::parse($request->get('to_date'))->startOfMonth()
            : Carbon::now()->startOfMonth();

        // Generate last 12 months
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[] = $referenceDate->copy()->subMonths($i + 1)->format('Y-m');
        }
        $months = array_reverse($months);

        $startDate = Carbon::parse($months[0] . '-01')->startOfMonth();
        $endDate   = Carbon::parse(end($months) . '-01')->endOfMonth();

        // Aggregation: DISTINCT client_id with order in that month
        $aggregated = OrderReport::query()
            ->select(
                DB::raw("DATE_FORMAT(order_reports.final_status_at, '%Y-%m') as order_month"),
                DB::raw("COUNT(DISTINCT CASE WHEN clients.created_at >= DATE_FORMAT(order_reports.final_status_at, '%Y-%m-01') 
                                                 AND clients.created_at <= LAST_DAY(order_reports.final_status_at)
                                         THEN clients.id END) as new_client_count"),
                DB::raw("COUNT(DISTINCT CASE WHEN clients.created_at < DATE_FORMAT(order_reports.final_status_at, '%Y-%m-01')
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

        $labels = [];
        $newClientOrders = [];
        $existingClientOrders = [];
        $success_rate = [];

        foreach ($months as $month) {
            $labels[] = Carbon::parse($month . '-01')->format('M-y');
            $row = $aggregated[$month] ?? null;

            $newClientOrders[] = $row->new_client_count ?? 0;
            $existingClientOrders[] = $row->existing_client_count ?? 0;
            $success_rate[] = ($row->new_client_count ?? 0) + ($row->existing_client_count ?? 0);
        }

        return [
            'colors'       => $colors,
            'labels'       => $labels,
            'activeClient' => $existingClientOrders,
            'newClient'    => $newClientOrders,
            'success_rate' => $success_rate,
        ];
    }
}
