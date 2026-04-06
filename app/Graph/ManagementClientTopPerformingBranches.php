<?php
namespace App\Graph;

use App\OrderReport;
use Illuminate\Support\Facades\DB;

class ManagementClientTopPerformingBranches implements Graph
{
    public function data($request = null)
    {
        // $startDate = request()->has('from_date') ? now()->parse(request()->get('from_date'))->startOfDay() : now()->startOfDay();
        // $endDate = request()->has('to_date') ? now()->parse(request()->get('to_date'))->endOfDay() : now()->endOfDay();

        $fromDate = $request->get('from_date', now()->format('Y-m-d'));
        $toDate = $request->get('to_date', now()->format('Y-m-d'));

        //This is for showing the date range in the dashboard
        $fromDateTime = \Carbon\Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDateTime =  \Carbon\Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        //This is for filtering the orders based on the date range
        $startDate = $fromDateTime->format('Y-m-d H:i:s');
        $endDate =  $toDateTime->format('Y-m-d H:i:s');

        $client = request()->get('client');

        $colors = [
            '#36a2eb',
            '#ff6384',
            '#ff9f40',
            '#ffcd56',
            '#14b8a6',
            '#6b7280',
            '#1f6f70',
            '#b5d1af',
            '#5f4c60',
            '#97a7c4',
            '#F5921B',
            '#e1a692',
            '#54bebe',
            '#dedad2',
            '#badbdb',
            '#e8daff',
            '#ff8389',
            '#3ddbd9',
            '#20B2AA',
            '#ADD8E6',
            '#90EE90',
            '#FF6347',
        ];

        $labels = [];
        $values = [];

        $data = OrderReport::
            select(
            'shop_id',
            DB::raw('count(*) as order_count'),
            DB::raw("CONCAT(shop.name) AS branch_name")
        )
            ->leftJoin('client_shops as shop', 'shop.id', '=', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'shop.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
            ->when($client, function ($query) use ($client) {
                $query->where('order_reports.client_id', $client);
            })
            ->finishedOrders()
            ->excludeQuadrants('shop_region.quadrant_id')
            ->groupBy('shop_id')
            ->orderByDesc('order_count')
            ->belongsToMe()
            ->limit(10)
            ->get();

        $labels = $data->pluck('branch_name')->toArray();
        $values = $data->pluck('order_count')->toArray();

        return [
            'colors' => $colors,
            'labels' => $labels,
            'values' => $values,
        ];
    }
}
