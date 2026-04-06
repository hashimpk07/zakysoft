<?php
namespace App\Graph;

use App\OrderReport;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ActiveBranchOverMonth implements Graph
{
    public function data($request = null)
    {
        $colors = ['#36a2eb', '#2f01d6ff', '#1f6f70'];

        $regionId = $request->input('region');
        $clientId = $request->input('client');

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

        $aggregated = OrderReport::query()
            ->select(
                DB::raw("DATE_FORMAT(order_reports.final_status_at, '%Y-%m') as order_month"),
                DB::raw("COUNT(DISTINCT CASE WHEN client_shops.created_at >= DATE_FORMAT(order_reports.final_status_at, '%Y-%m-01') 
                                                 AND client_shops.created_at <= LAST_DAY(order_reports.final_status_at)
                                         THEN client_shops.id END) as new_branch_count"),
                DB::raw("COUNT(DISTINCT CASE WHEN client_shops.created_at < DATE_FORMAT(order_reports.final_status_at, '%Y-%m-01')
                                         THEN client_shops.id END) as existing_branch_count")
            )
            ->leftJoin('client_shops', 'client_shops.id', '=', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', '=', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', '=', 'shop_zone.region_id')
            ->when($regionId, fn($q) => $q->where('shop_region.quadrant_id', $regionId))
            ->when($clientId, fn($q) => $q->where('client_shops.client_id', $clientId))
            ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
            ->BelongsToMe()
            ->excludeQuadrants('shop_region.quadrant_id')
            ->groupBy('order_month')
            ->orderBy('order_month', 'asc')
            ->get()
            ->keyBy('order_month');

        $labels = [];
        $newBranchOrders = [];
        $existingBranchOrders = [];
        $success_rate = [];

        foreach ($months as $month) {
            $labels[] = Carbon::parse($month . '-01')->format('M-y');
            $row = $aggregated[$month] ?? null;

            $newBranchOrders[] = $row->new_branch_count ?? 0;
            $existingBranchOrders[] = $row->existing_branch_count ?? 0;
            $success_rate[] = ($row->new_branch_count ?? 0) + ($row->existing_branch_count ?? 0);
        }

        return [
            'colors'       => $colors,
            'labels'       => $labels,
            'activeClient' => $existingBranchOrders,
            'newClient'    => $newBranchOrders,
            'success_rate' => $success_rate,
        ];
    }
}
