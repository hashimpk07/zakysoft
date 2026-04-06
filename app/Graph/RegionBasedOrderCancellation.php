<?php
namespace App\Graph;

use App\OrderReport;
use App\OrderStatus;
use App\Quadrant;
use App\Region;
use Illuminate\Support\Facades\DB;

class RegionBasedOrderCancellation implements Graph
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

        $quadrant  = request()->get('quadrant');

        $colors = [
            '#156082', // Cancelled Orders
            '#e97132', // Delivered Orders
            '#196b24', // Success Rate Line
        ];

        $title = $quadrant ? 'ORDER CANCELLATION BY AREA' : 'ORDER CANCELLATION BY REGION';

        $labels          = [];
        $cancelledOrders = [];

        if ($quadrant) {
            $regions = Region::toBase()
                ->select('name', 'id')
                ->where('regions.quadrant_id', $quadrant)
                ->get();

            $data = OrderReport::select(
                DB::raw('COALESCE(shop_region.id, "-1") as region_id'),
                DB::raw('SUM(CASE WHEN order_reports.status_id IN (?, ?, ?, ?) THEN 1 ELSE 0 END) as cancelled_orders'),
            )
                ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
                ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
                ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
                ->whereRaw('shop_region.quadrant_id = ?', [$quadrant])
                ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
                ->groupByRaw('COALESCE(shop_region.id, "-1")')
                ->setBindings([
                    OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::FORYOU_RETURN_ACCEPTED,
                ], 'select')
                ->belongsToMe()
                ->excludeQuadrants('shop_region.quadrant_id')
                ->get()
                ->keyBy('region_id');

            foreach ($regions as $region) {
                $regionId  = $region->id;
                $cancelled = (int) ($data[$regionId]->cancelled_orders ?? 0);

                $labels[]          = $region->name;
                $cancelledOrders[] = $cancelled;
            }
        } else {
            $quadrants = Quadrant::excludeQuadrants()->toBase()->select('name', 'id')->get();

            $data = OrderReport::select(
                DB::raw('COALESCE(shop_quadrant.id, "-1") as quadrant_id'),
                DB::raw('SUM(CASE WHEN order_reports.status_id IN (?, ?, ?, ?) THEN 1 ELSE 0 END) as cancelled_orders'),
            )
                ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
                ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
                ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
                ->leftJoin('quadrants as shop_quadrant', 'shop_region.quadrant_id', 'shop_quadrant.id')
                ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
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
        }

        return [
            'colors'    => $colors,
            'labels'    => $labels,
            'cancelled' => $cancelledOrders,
            'title'     => $title,
        ];

    }
}
