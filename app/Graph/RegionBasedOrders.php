<?php
namespace App\Graph;

use App\OrderReport;
use App\OrderStatus;
use App\Quadrant;
use App\Region;
use Illuminate\Support\Facades\DB;

class RegionBasedOrders implements Graph
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

        $quadrant = request()->get('quadrant');

        $title = $quadrant ? 'ORDERS BY AREA' : 'ORDERS BY REGIONS';

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

        if ($quadrant) {
            $regions = Region::toBase()
                ->select('name', 'id')
                ->where('regions.quadrant_id', $quadrant)
                ->get();

            $data = OrderReport::
                select(
                    DB::raw('count(*) as region_orders'),
                    DB::raw('COALESCE(shop_region.id, "-1") as region_id'),
                )
                ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
                ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
                ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
                ->whereRaw("shop_region.quadrant_id = ?", [$quadrant])
                ->whereIn("order_reports.status_id", OrderStatus::FINISHED)
                ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
                ->groupByRaw('COALESCE(shop_region.id, "-1")')
                ->BelongsToMe()
                ->excludeQuadrants('shop_region.quadrant_id')
                ->get()
                ->keyBy('region_id');

            $totalOrders = $data->sum('region_orders');

            foreach ($regions as $key => $region) {
                $regionOrders = $data[$region->id]->region_orders ?? 0;
                $percentage = $totalOrders > 0 ? round(($regionOrders / $totalOrders) * 100, 2) : 0;
                $labels[] = $region->name;
                $values[] = "{$regionOrders} ({$percentage}%)"; // Append percentage inside the value itself
            }
        } else {

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
                ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
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


        }

        return [
            'colors' => $colors,
            'labels' => $labels,
            'values' => $values,
            'title' => $title,
        ];
    }
}
