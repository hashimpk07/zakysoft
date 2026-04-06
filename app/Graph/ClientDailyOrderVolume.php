<?php
namespace App\Graph;

use App\OrderReport;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;

class ClientDailyOrderVolume implements Graph
{
    public function data($request = null)
    {
        $colors = [
            '#156082', // cancelled color
            '#e97132', // cancelled color
        ];
        $labels = [];
        $values = [];
        //$date   = request()->has('to_date') ? now()->parse(request()->get('to_date'))->format('Y-m-d') : now()->format('Y-m-d');
        $toDate = $request->get('to_date', now()->format('Y-m-d')); // to_date 19-07-2025
        $toDateTime =  \Carbon\Carbon::parse($toDate)->addDay()->setTime(5, 59, 59); // business to_date 20-07-2025 05:59:59
        $date =  $toDateTime->format('Y-m-d H:i:s'); // to_date_time 20-07-2025 05:59:59

        $uptoDate = now()->parse($toDate)->subDays(6)->format('Y-m-d'); // upto_date 13-07-2025
        $uptoDateTime =  \Carbon\Carbon::parse($uptoDate)->setTime(6, 0, 0); // business upto_date 13-07-2025 06:00:00
        $upto_date = $uptoDateTime->format('Y-m-d H:i:s'); // upto_date_time 13-07-2025 06:00:00

        $client = request()->get('client');

        //DB::enableQueryLog(); // Enable query log for debugging
        $query = OrderReport::belongsToMe()
            ->select(
                DB::raw('count(*) as no_of_orders'),
                DB::raw("DATE(DATE_SUB(order_reports.final_status_at, INTERVAL 6 HOUR)) as date"), // Business day grouping
                DB::raw('SUM(CASE WHEN order_reports.status_id IN (?, ?, ? , ?) THEN 1 ELSE 0 END) as cancelled_orders'),
                DB::raw('SUM(CASE WHEN order_reports.status_id = ? THEN 1 ELSE 0 END) as delivered_orders')
            )
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->latest('date')
            ->excludeQuadrants('shop_region.quadrant_id')
            ->setBindings([
                OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::FORYOU_RETURN_ACCEPTED,
                OrderStatus::DELIVERED,
            ], 'select')
            ->whereRaw("order_reports.final_status_at >= ? AND order_reports.final_status_at <= ?", [$upto_date, $date])
            //->groupByRaw('date');
            ->groupByRaw("DATE(DATE_SUB(order_reports.final_status_at, INTERVAL 6 HOUR))");

        if ($client) {
            $query->where('order_reports.client_id', '=', $client);
        }

        $data = $query->get();
        //dd($data); // Debugging: check the executed query

        $loop        = true;
        $index       = 0;
        $date_parsed = now()->parse($date)->subHours(6);

        $labels           = [];
        $delivered_orders = [];
        $cancelled_orders = [];
        $success_rate     = [];

        while ($loop) {
            $current_date = $date_parsed->format('Y-m-d');
            //var_dump($current_date);
            $labels[]     = $date_parsed->format('D'); // Day of the week

            // Check if there's data for the current date
            if (isset($data[$index]) && $data[$index]->date == $current_date) {
                $delivered_orders[] = $data[$index]->delivered_orders;
                $cancelled_orders[] = $data[$index]->cancelled_orders;

                $total_orders   = $data[$index]->no_of_orders;
                $success_rate[] = $total_orders > 0 ? round(($data[$index]->delivered_orders / $total_orders) * 100, 2) : 0;

                $index++;
            } else {
                // No data for this date
                $delivered_orders[] = 0;
                $cancelled_orders[] = 0;
                $success_rate[]     = 0;
            }

            // Check if we've reached the starting date (upto_date)
            if ($current_date == $uptoDate) {
                $loop = false;
            }

            // Move to the previous day for the next iteration
            $date_parsed = $date_parsed->subDay();
        }

        return [
            'colors'           => $colors,
            'labels'           => $labels,
            'delivered_orders' => $delivered_orders,
            'cancelled_orders' => $cancelled_orders,
            'success_rate'     => $success_rate,

        ];
    }
}
