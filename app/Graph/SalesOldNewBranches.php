<?php
namespace App\Graph;

use App\OrderReport;
use App\ClientShop;
use Illuminate\Support\Facades\DB;

class SalesOldNewBranches implements Graph
{

      public function data($request = null)
    {
        $colors = [
            '#84cc16', 
            '#eab308', 
        ];

        $startDate = request()->has('from_date')
        ? now()->parse(request()->get('from_date'))->startOfDay()
        : now()->startOfDay();

        $endDate = request()->has('to_date')
        ? now()->parse(request()->get('to_date'))->endOfDay()
        : now()->endOfDay();

        $fromDate = $request->get('from_date', now()->format('Y-m-d'));
        $toDate = $request->get('to_date', now()->format('Y-m-d'));

        //This is for showing the date range in the dashboard
        $fromDateTime = \Carbon\Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDateTime =  \Carbon\Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        //This is for filtering the orders based on the date range
        $startDate = $fromDateTime->format('Y-m-d H:i:s');
        $endDate =  $toDateTime->format('Y-m-d H:i:s');

        $labels = [];

        $totalBranch = ClientShop::query()
                        ->leftJoin('zones as shop_zone', 'shop_zone.id', '=', 'client_shops.zone_id')
                        ->leftJoin('regions as shop_region', 'shop_region.id', '=', 'shop_zone.region_id')
                        ->excludeQuadrants('shop_region.quadrant_id')
                        ->belongsToMe()
                        ->selectRaw('COUNT(DISTINCT client_shops.id) as total')
                        ->value('total');

        $newBranch = ClientShop::query()
                        ->whereBetween('client_shops.created_at', [$startDate, $endDate])
                        ->leftJoin('zones as shop_zone', 'shop_zone.id', '=', 'client_shops.zone_id')
                        ->leftJoin('regions as shop_region', 'shop_region.id', '=', 'shop_zone.region_id')
                        ->excludeQuadrants('shop_region.quadrant_id')
                        ->belongsToMe()
                        ->selectRaw('COUNT(DISTINCT client_shops.id) as total')
                        ->value('total');


        $oldBranch = $totalBranch - $newBranch;
        return [
            'colors'           => $colors,
            'labels'           => $labels,
            'new_branch'       => $newBranch,
            'old_branch'       => $oldBranch,
            
        ];
    }
}
