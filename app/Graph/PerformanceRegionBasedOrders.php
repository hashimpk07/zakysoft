<?php
namespace App\Graph;

use App\OrderReport;
use App\OrderStatus;
use App\Quadrant;
use App\Region;
use Illuminate\Support\Facades\DB;

class PerformanceRegionBasedOrders implements Graph
{
    public function data()
    {
        $date = request()->has('date') ? now()->parse(request()->get('date')) : now()->subDay();
        $title = 'ORDERS BY REGIONS';

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
        // need count from order based on quatrand

        $quadrants = Quadrant::excludeQuadrants()->toBase()->select('name', 'id')->get();

        $data = OrderReport::
            select(
                DB::raw('count(*) as region_orders'),
                DB::raw('COALESCE(shop_quadrant.id, "-1") as quadrant_id'),
            )
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->leftJoin('quadrants as shop_quadrant', 'shop_region.quadrant_id', 'shop_quadrant.id')
            ->whereIn("order_reports.status_id", OrderStatus::FINISHED)
            ->whereDate('order_reports.final_status_at', $date)
            ->groupByRaw('COALESCE(shop_quadrant.id, "-1")')
            ->belongsToMe()
            ->excludeQuadrants('shop_region.quadrant_id')
            ->get()
            ->keyBy('quadrant_id');

        $totalOrders = $data->sum('region_orders');

        foreach ($quadrants as $key => $region) {
            $regionOrders = $data[$region->id]->region_orders ?? 0;
            $percentage = $totalOrders > 0 ? round(($regionOrders / $totalOrders) * 100, 2) : 0;

            $labels[] = $region->name;
            $values[] = "{$regionOrders} ({$percentage}%)"; // Append percentage inside the value itself
        }

        return [
            'colors' => $colors,
            'labels' => $labels,
            'values' => $values,
            'title' => $title,
        ];
    }
}
