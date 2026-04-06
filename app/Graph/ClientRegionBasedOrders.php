<?php
namespace App\Graph;

use App\OrderReport;
use App\Quadrant;
use Illuminate\Support\Facades\DB;

class ClientRegionBasedOrders implements Graph
{
    public function data()
    {
        $startDate = request()->has('from_date') ? now()->parse(request()->get('from_date'))->startOfDay() : now()->startOfDay();
        $endDate   = request()->has('to_date') ? now()->parse(request()->get('to_date'))->endOfDay() : now()->endOfDay();

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
            ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
            ->groupByRaw('COALESCE(shop_quadrant.id, "-1")')
            ->excludeQuadrants('shop_region.quadrant_id')
            ->belongsToMe()
            ->when($client, function ($query) use ($client) {
                $query->where('client_shops.client_id', $client);
            })
            ->finishedOrders()
            ->get()
            ->keyBy('quadrant_id');

        $totalOrders = $data->sum('region_orders');

        foreach ($quadrants as $key => $region) {
            $labels[] = $region->name;
            $values[] = $data[$region->id]->region_orders ?? 0;
        }

        if ($data['-1']->region_orders ?? 0) {
            $labels[] = 'Unknown';
            $values[] = $data['-1']->region_orders ?? 0;
        }

        return [
            'colors' => $colors,
            'labels' => $labels,
            'values' => $values,
            'total'  => $totalOrders,
        ];
    }
}
