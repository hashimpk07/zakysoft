<?php
namespace App\Graph;

use App\Order;
use App\OrderReport;
use App\OrderStatus;
use App\Quadrant;
use Illuminate\Support\Facades\DB;

class ClientRegionBasedOrdersAndDeliveries implements Graph
{
    public function data($request = null)
    {
        // $startDate = request()->has('from_date') ? now()->parse(request()->get('from_date'))->startOfDay() : now()->startOfDay();
        // $endDate   = request()->has('to_date') ? now()->parse(request()->get('to_date'))->endOfDay() : now()->endOfDay();

        $fromDate = $request->get('from_date', now()->format('Y-m-d'));
        $toDate = $request->get('to_date', now()->format('Y-m-d'));

        //This is for showing the date range in the dashboard
        $fromDateTime = \Carbon\Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDateTime =  \Carbon\Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        //This is for filtering the orders based on the date range
        $startDate = $fromDateTime->format('Y-m-d H:i:s');
        $endDate =  $toDateTime->format('Y-m-d H:i:s');

        $client    = request()->get('client');

        $colors = [
            '#156082', // Cancelled Orders
            '#e97132', // Delivered Orders
            '#196b24', // Success Rate Line
        ];

        $labels          = [];
        $cancelledOrders = [];
        $deliveredOrders = [];
        $successRate     = [];

        $quadrants = Quadrant::excludeQuadrants()->toBase()->select('name', 'id')->get();

        $data = OrderReport::select(
            DB::raw('COALESCE(shop_quadrant.id, "-1") as quadrant_id'),
            DB::raw('SUM(CASE WHEN order_reports.status_id IN (?, ?, ?, ?) THEN 1 ELSE 0 END) as cancelled_orders'),
            DB::raw('SUM(CASE WHEN order_reports.status_id = ? THEN 1 ELSE 0 END) as delivered_orders')
        )
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->when($client, function ($query, $client) {
                return $query->where('client_shops.client_id', $client);
            })
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->leftJoin('quadrants as shop_quadrant', 'shop_region.quadrant_id', 'shop_quadrant.id')
            ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
            ->groupByRaw('COALESCE(shop_quadrant.id, "-1")')
            ->setBindings([
                OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::FORYOU_RETURN_ACCEPTED,
                OrderStatus::DELIVERED,
            ], 'select')
            ->belongsToMe()
            ->excludeQuadrants('shop_quadrant.id')
            ->get()
            ->keyBy('quadrant_id');

        foreach ($quadrants as $quadrant) {
            $quadrantId = $quadrant->id;
            $cancelled  = $data[$quadrantId]->cancelled_orders ?? 0;
            $delivered  = $data[$quadrantId]->delivered_orders ?? 0;

            $labels[]          = $quadrant->name;
            $cancelledOrders[] = $cancelled;
            $deliveredOrders[] = $delivered;
            $successRate[]     = $delivered > 0 ? round(($delivered / ($cancelled + $delivered)) * 100, 2) : 0;
        }

        return [
            'colors'       => $colors,
            'labels'       => $labels,
            'cancelled'    => $cancelledOrders,
            'delivered'    => $deliveredOrders,
            'success_rate' => $successRate,

        ];

    }
}
