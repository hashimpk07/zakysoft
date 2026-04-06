<?php
namespace App\Graph;

use App\OrderReport;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;

class ClientDailyOrders implements Graph
{

    public function data($request = null)
    {
        $colors = [
            '#156082', // cancelled color
            '#e97132', // cancelled color
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

        $client = request()->get('client');

        // Query with startDate and endDate filters
        $query = OrderReport::belongsToMe()
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->select(
                DB::raw('count(*) as no_of_orders'),
                DB::raw('DATE(order_reports.final_status_at) as date'),
                DB::raw('SUM(CASE WHEN order_reports.status_id IN (?, ?, ?, ?) THEN 1 ELSE 0 END) as cancelled_orders'),
                DB::raw('SUM(CASE WHEN order_reports.status_id = ? THEN 1 ELSE 0 END) as delivered_orders')
            )
            ->excludeQuadrants('shop_region.quadrant_id')
            ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
            ->setBindings([
                OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::FORYOU_RETURN_ACCEPTED,
                OrderStatus::DELIVERED,
            ], 'select')

            ->groupByRaw('DATE(order_reports.final_status_at)')
            ->latest('date');

        if ($client) {
            $query->where('order_reports.client_id', '=', $client);
        }

        $data = $query->get();

        $date                   = $fromDateTime->copy();
        $labels                 = [];
        $total_delivered_orders = 0;
        $total_cancelled_orders = 0;
        $success_rate           = [];

        while ($date->lte($endDate)) {
            $current_date = $date->format('Y-m-d');
            $labels[]     = $date->format('D');

            $dayData = $data->firstWhere('date', $current_date);
            if ($dayData) {
                $total_delivered_orders += $dayData->delivered_orders;
                $total_cancelled_orders += $dayData->cancelled_orders;

                $total_orders   = $dayData->no_of_orders;
                $success_rate[] = $total_orders > 0 ? ($dayData->delivered_orders / $total_orders) * 100 : 0;
            } else {
                $success_rate[] = 0;
            }

            $date->addDay();
        }
        return [
            'colors'           => $colors,
            'labels'           => $labels,
            'delivered_orders' => $total_delivered_orders,
            'cancelled_orders' => $total_cancelled_orders,
            'success_rate'     => $success_rate,
        ];
    }
}
