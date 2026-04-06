<?php
namespace App\Graph;

use App\OrderReport;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;

class ClientMonthlyOrderVolume implements Graph
{
    public function data()
    {
        $colors = [
            '#36a2eb', '#ff6384', '#ff9f40', '#ffcd56', '#14b8a6', '#6b7280',
            '#1f6f70', '#b5d1af', '#5f4c60', '#97a7c4', '#F5921B', '#e1a692',
            '#54bebe', '#dedad2', '#badbdb', '#e8daff', '#ff8389', '#3ddbd9',
            '#20B2AA', '#ADD8E6', '#90EE90', '#FF6347',
        ];

        $toDate = request()->get('to_date', now()->format('Y-m-d'));

        // Define business time range (6:00 AM to next day 5:59:59 AM)
        $toDateTime = \Carbon\Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);
        $fromDateTime = \Carbon\Carbon::parse($toDate)->subMonths(11)->startOfMonth()->setTime(6, 0, 0);

        $client = request()->get('client');

        $query = OrderReport::belongsToMe()
            ->select(
                DB::raw('count(*) as no_of_orders'),
                DB::raw('DATE_FORMAT(order_reports.final_status_at, "%Y-%m") as month'), // Group by month
                DB::raw('SUM(CASE WHEN order_reports.status_id IN (?, ?, ?, ?) THEN 1 ELSE 0 END) as cancelled_orders'),
                DB::raw('SUM(CASE WHEN order_reports.status_id = ? THEN 1 ELSE 0 END) as delivered_orders')
            )
            ->setBindings([
                OrderStatus::CANCEL,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
                OrderStatus::CANCEL_REQUEST_ACCEPTED,
                OrderStatus::FORYOU_RETURN_ACCEPTED,
                OrderStatus::DELIVERED,
            ], 'select')
            ->businessDayBetween(
                $fromDateTime->format('Y-m-d H:i:s'),
                $toDateTime->format('Y-m-d H:i:s')
            )
            ->groupByRaw('month')
            ->excludeQuadrants()
            ->orderBy('month', 'asc');

        if ($client) {
            $query->where('order_reports.client_id', '=', $client);
        }

        $data = $query->get();

        // Prepare chart data
        $labels = [];
        $delivered_orders = [];
        $cancelled_orders = [];
        $success_rate = [];

        $current_date = now()->parse($toDate)->startOfMonth();
        $start_date = now()->parse($toDate)->subMonths(11)->startOfMonth();

        while ($current_date >= $start_date) {
            $monthKey = $current_date->format('Y-m');
            $labels[] = $current_date->format('M Y');

            $monthData = $data->firstWhere('month', $monthKey);
            //dd($monthData);

            if ($monthData) {
                $delivered_orders[] = $monthData->delivered_orders;
                $cancelled_orders[] = $monthData->cancelled_orders;
                $total = $monthData->no_of_orders;

                $success_rate[] = $total > 0 ? round(($monthData->delivered_orders / $total) * 100, 2) : 0;
            } else {
                $delivered_orders[] = 0;
                $cancelled_orders[] = 0;
                $success_rate[] = 0;
            }

            $current_date->subMonth();
        }

        return [
            'colors' => $colors,
            'labels' => array_reverse($labels),
            'delivered_orders' => array_reverse($delivered_orders),
            'cancelled_orders' => array_reverse($cancelled_orders),
            'success_rate' => array_reverse($success_rate),
        ];
    }

    public function data_old()
    {
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
        $date = request()->has('to_date')
        ? now()->parse(request()->get('to_date'))->format('Y-m-d')
        : now()->format('Y-m-d');

        $from_date = now()->parse($date)->subMonths(11)->startOfMonth()->format('Y-m-d'); // Start of the earliest month

        $client = request()->get('client');

        $query = OrderReport::belongsToMe()
            ->select(
                DB::raw('count(*) as no_of_orders'),
                DB::raw('DATE_FORMAT(order_reports.final_status_at, "%Y-%m") as month'), // Group by month
                DB::raw('SUM(CASE WHEN order_reports.status_id IN (?, ?, ?, ?) THEN 1 ELSE 0 END) as cancelled_orders'),
                DB::raw('SUM(CASE WHEN order_reports.status_id = ? THEN 1 ELSE 0 END) as delivered_orders')
            )
            ->setBindings([
                OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::FORYOU_RETURN_ACCEPTED,
                OrderStatus::DELIVERED,
            ], 'select')
            ->whereRaw("DATE(order_reports.final_status_at) >= ? AND DATE(order_reports.final_status_at) <= ?", [$from_date, $date])
            ->groupByRaw('month')
            ->excludeQuadrants()
            ->orderBy('month', 'asc');

        if ($client) {
            $query->where('order_reports.client_id', '=', $client);
        }

        $data = $query->get();

        $labels           = [];
        $delivered_orders = [];
        $cancelled_orders = [];
        $success_rate     = [];

        $current_date = now()->parse($date)->startOfMonth();
        $start_date   = now()->parse($from_date)->startOfMonth();

        while ($current_date >= $start_date) {
            $current_month = $current_date->format('Y-m');
            $labels[]      = $current_date->format('M Y');

            $month_data = $data->firstWhere('month', $current_month);

            if ($month_data) {
                $delivered_orders[] = $month_data->delivered_orders;
                $cancelled_orders[] = $month_data->cancelled_orders;

                $total_orders   = $month_data->no_of_orders;
                $success_rate[] = $total_orders > 0 ? round(($month_data->delivered_orders / (float) $total_orders) * 100, 2) : 0;
            } else {
                $delivered_orders[] = 0;
                $cancelled_orders[] = 0;
                $success_rate[]     = 0;
            }

            $current_date = $current_date->subMonth();
        }
        return [
            'colors'           => $colors,
            'labels'           => array_reverse($labels), // Reverse to get chronological order
            'delivered_orders' => array_reverse($delivered_orders),
            'cancelled_orders' => array_reverse($cancelled_orders),
            'success_rate'     => array_reverse($success_rate),
        ];
    }

}
