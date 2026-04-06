<?php
namespace App\Graph;

use App\OrderReport;
use App\OrderStatus;
use App\Client as ClientModel;
use App\Services\Adapters\Clients\Client;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;


class SalesActiveInactiveClients implements Graph
{

    public function data($request = null)
    {
        $colors = [
            '#14b8a6', // Active client
            '#36a2eb', // Inactive client
        ];
        $fromDate = $request->get('from_date');
        $toDate = $request->get('to_date');

        // Default end date is today 05:59:59 AM
        $endDate = $toDate
        ? Carbon::parse($toDate)->addDay()->setTime(5, 59, 59)
        : Carbon::now()->setTime(5, 59, 59);

        // Default start date is 7 days before end date at 06:00:00 AM
        $startDate = $fromDate
        ? Carbon::parse($fromDate)->setTime(6, 0, 0)
        : $endDate->copy()->subDays(1)->setTime(6, 0, 0);

        $sDate    =  $startDate->format('Y-m-d H:i:s');
        $eDate    =  $endDate->format('Y-m-d H:i:s');
    
        $activeClients = OrderReport::selectRaw('COUNT(DISTINCT order_reports.client_id) as total')
                ->leftJoin('client_shops', 'client_shops.id', '=', 'order_reports.shop_id')
                ->leftJoin('zones as shop_zone', 'shop_zone.id', '=', 'client_shops.zone_id')
                ->leftJoin('regions as shop_region', 'shop_region.id', '=', 'shop_zone.region_id')
                ->whereBetween('order_reports.final_status_at', [$sDate, $eDate])
                ->finishedOrders()
                ->excludeQuadrants('shop_region.quadrant_id')
                ->belongsToMe()
                ->value('total');

        $subQuery = ClientModel::query()
            ->selectRaw('COALESCE(quadrants.id, 0) as quadrant_id')
            ->selectRaw('COUNT(DISTINCT clients.id) as total_client_count')
            ->leftJoin('client_shops', 'client_shops.client_id', '=', 'clients.id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', '=', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', '=', 'shop_zone.region_id')
            ->leftJoin('quadrants', 'quadrants.id', '=', 'shop_region.quadrant_id')
            ->excludeQuadrants()   
            ->belongsToMe()        
            ->groupBy(DB::raw('COALESCE(quadrants.id, 0)'));

        $totalClients = DB::query()
            ->fromSub($subQuery, 't')
            ->selectRaw('SUM(t.total_client_count) as total_clients_sum')
            ->value('total_clients_sum');
         
        $inactiveClient = $totalClients - $activeClients;
    
        $labels                 = [];
     
        return [
            'colors'            => $colors,
            'labels'            => $labels,
            'active_client'     => $activeClients,
            'inactive_client'   => $inactiveClient,
           
        ];
    }
}
