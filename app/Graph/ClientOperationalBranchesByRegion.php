<?php
namespace App\Graph;

use App\OrderReport;
use App\Quadrant;
use Illuminate\Support\Facades\DB;

class ClientOperationalBranchesByRegion implements Graph
{
    public function data()
    {

        $startDate = request()->has('from_date') ? now()->parse(request()->get('from_date'))->startOfDay() : now()->startOfDay();
        $endDate   = request()->has('to_date') ? now()->parse(request()->get('to_date'))->endOfDay() : now()->endOfDay();

        $quadrants = request()->get('quadrant')
        ? Quadrant::toBase()->select('name', 'id')->where('id', request()->get('quadrant'))->first()
        : Quadrant::excludeQuadrants()->toBase()->select('name', 'id')->get();

        $client = request()->get('client');

        $data = OrderReport::query()
            ->select(
                DB::raw('COUNT(DISTINCT order_reports.shop_id) as branch_count'),
                DB::raw('COALESCE(shop_region.quadrant_id, "-1") as quadrant_id')
            )
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions', 'regions.id', 'shop_zone.region_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->when($client, function ($query) use ($client) {
                return $query->where('order_reports.client_id', $client);
            })
            ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
            ->finishedOrders()
            ->excludeQuadrants('shop_region.quadrant_id')
            ->belongsToMe()
            ->groupByRaw('COALESCE(shop_region.quadrant_id, "-1")')
            ->get()
            ->keyBy('quadrant_id');

        $labels = [];
        $values = [];
        $total  = 0;

        foreach ($quadrants as $quadrant) {
            $labels[] = $quadrant->name;
            $count    = $data[$quadrant->id]->branch_count ?? 0;
            $values[] = $count;
            $total += $count;
        }

        if (isset($data['-1'])) {
            $labels[] = 'Not Specified';
            $values[] = $data['-1']->branch_count ?? 0;
            $total += $data['-1']->branch_count ?? 0;
        }

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

        return [
            'labels' => $labels,
            'values' => $values,
            'total'  => $total,
            'colors' => $colors,
        ];

    }
}
