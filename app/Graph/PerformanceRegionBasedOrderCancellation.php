<?php
namespace App\Graph;

use App\OrderReport;
use App\OrderStatus;
use App\Quadrant;
use App\Region;
use Illuminate\Support\Facades\DB;

class PerformanceRegionBasedOrderCancellation implements Graph
{
    public function data()
    {
        $date= request()->has('date') ? now()->parse(request()->get('date')) : now()->subDay();

        $colors = [
            '#156082', // Cancelled Orders
            '#e97132', // Delivered Orders
            '#196b24', // Success Rate Line
            '#be185d'
        ];

        $title = 'ORDER CANCELLATION BY REGION';

        $labels          = [];
        $cancelledOrders = [];

        $quadrants = Quadrant::excludeQuadrants()->toBase()->select('name', 'id')->get();

        $data = OrderReport::select(
            DB::raw('COALESCE(shop_quadrant.id, "-1") as quadrant_id'),
            DB::raw('SUM(CASE WHEN order_reports.status_id IN (?, ?, ?, ?) THEN 1 ELSE 0 END) as cancelled_orders'),
        )
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->leftJoin('quadrants as shop_quadrant', 'shop_region.quadrant_id', 'shop_quadrant.id')
            ->whereDate('order_reports.final_status_at', $date)
            ->groupByRaw('COALESCE(shop_quadrant.id, "-1")')
            ->setBindings([
                OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::FORYOU_RETURN_ACCEPTED,
            ], 'select')
            ->belongsToMe()
            ->excludeQuadrants('shop_quadrant.id')
            ->get()
            ->keyBy('quadrant_id');

        foreach ($quadrants as $quadrant) {
            $quadrantId = $quadrant->id;
            $cancelled  = (int) ($data[$quadrantId]->cancelled_orders ?? 0);

            $labels[]          = $quadrant->name;
            $cancelledOrders[] = $cancelled;
        }

        return [
            'colors'    => $colors,
            'labels'    => $labels,
            'cancelled' => $cancelledOrders,
            'title'     => $title,
        ];
    }
}
