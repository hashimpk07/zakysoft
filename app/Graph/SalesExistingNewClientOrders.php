<?php
namespace App\Graph;

use App\OrderReport;

class SalesExistingNewClientOrders implements Graph
{

    public function data($request = null)
    {
        $colors = [
            '#ffcd56', 
            '#14b8a6',
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

       $totalOrder = OrderReport::query()
                    ->selectRaw('COUNT(DISTINCT order_reports.id) as total')
                    ->leftJoin('client_shops', 'client_shops.id', '=', 'order_reports.shop_id')
                    ->leftJoin('zones as shop_zone', 'shop_zone.id', '=', 'client_shops.zone_id')
                    ->leftJoin('regions as shop_region', 'shop_region.id', '=', 'shop_zone.region_id')
                    ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
                    ->excludeQuadrants('shop_region.quadrant_id')
                    ->belongsToMe()
                    ->value('total');   

        $newClientOrderCount = OrderReport::query()
                    ->selectRaw('COUNT(DISTINCT order_reports.id) as total')
                    ->join('clients', 'clients.id', '=', 'order_reports.client_id')
                    ->leftJoin('client_shops', 'client_shops.client_id', '=', 'clients.id')
                    ->leftJoin('zones as shop_zone', 'shop_zone.id', '=', 'client_shops.zone_id')
                    ->leftJoin('regions as shop_region', 'shop_region.id', '=', 'shop_zone.region_id')
                    ->whereBetween('clients.created_at', [$startDate, $endDate])
                    ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
                    ->excludeQuadrants('shop_region.quadrant_id')
                    ->belongsToMe()
                    ->value('total');   

        $existingClientOrder = $totalOrder - $newClientOrderCount;
    
        $existingClientOrder = $totalOrder - $newClientOrderCount;
        return [
            'colors'           => $colors,
            'labels'           => $labels,
            'new_client_order' => $newClientOrderCount,
            'existing_client_order' => $existingClientOrder,
           
        ];
    }
}
