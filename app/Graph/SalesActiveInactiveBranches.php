<?php
namespace App\Graph;

use App\OrderReport;
use App\ClientShop;
use Illuminate\Support\Facades\DB;

class SalesActiveInactiveBranches implements Graph
{
    public function data($request = null)
    {
        $colors = [
            '#84cc16', 
            '#eab308', 
        ];

        $fromDate = $request->get('from_date', now()->format('Y-m-d'));
        $toDate   = $request->get('to_date', now()->format('Y-m-d'));

        // Dashboard display range (6:00 AM .. next day 05:59:59)
        $fromDateTime = \Carbon\Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDateTime   = \Carbon\Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        // DB-friendly strings
        $startDate = $fromDateTime->format('Y-m-d H:i:s');
        $endDate   = $toDateTime->format('Y-m-d H:i:s');

        $labels = [];

        // COUNT(DISTINCT client_shops.id) with excludeQuadrants + belongsToMe
        $totalBranch = ClientShop::query()
            ->leftJoin('zones as shop_zone', 'shop_zone.id', '=', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', '=', 'shop_zone.region_id')
            ->excludeQuadrants('shop_region.quadrant_id')   
            ->belongsToMe()                                
            ->distinct()
            ->count('client_shops.id');

  
        $activeBranch = OrderReport::query()
            ->join('client_shops', 'client_shops.id', '=', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', '=', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', '=', 'shop_zone.region_id')
            ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
            ->finishedOrders()                                
            ->excludeQuadrants('shop_region.quadrant_id')     
            ->belongsToMe()                                 
            ->distinct()
            ->count('order_reports.shop_id');                 

        $inactiveBranch = max(0, $totalBranch - $activeBranch);

        return [
            'colors'           => $colors,
            'labels'           => $labels,
            'active_branch'    => (int) $activeBranch,
            'inactive_branch'  => (int) $inactiveBranch,
        ];
    }
}
