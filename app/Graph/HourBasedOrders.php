<?php
namespace App\Graph;

use App\OrderReport;
use Carbon\Carbon;

class HourBasedOrders implements Graph
{
    public function data($request = null)
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

        $quadrant = request()->get('quadrant');

        $fromDate = $request->get('from_date', now()->format('Y-m-d'));
        $toDate = $request->get('to_date', now()->format('Y-m-d'));

        // Business day range: from 6:00 AM on fromDate to 5:59:59 AM the next day after toDate
        $fromDateTime = \Carbon\Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDateTime = \Carbon\Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        $startDate = $fromDateTime->format('Y-m-d H:i:s');
        $endDate = $toDateTime->format('Y-m-d H:i:s');

        // Query to fetch orders by shifted business hour
        $ordersQuery = OrderReport::whereBetween("order_reports.final_status_at", [$startDate, $endDate])
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->when($quadrant, function ($query) use ($quadrant) {
                $query->where('shop_region.quadrant_id', $quadrant);
            })
            ->belongsToMe()
            ->excludeQuadrants('shop_region.quadrant_id');

        // Shift timestamp by 6 hours in groupBy to align with business hour
        $orders = $ordersQuery
            ->toBase()
            ->selectRaw('HOUR(order_reports.final_status_at) AS hour, COUNT(*) AS count')
            ->groupByRaw('hour')
            ->orderBy('hour')
            ->get()
            ->keyBy('hour');

        // Prepare business hour labels (6 AM to 5 AM) and values
        $hours = array_merge(range(6, 23), range(0, 5));
        $labels = [];
        $values = [];
        $total_orders = $orders->sum('count');

        foreach ($hours as $hour) {
            $meridiem = $hour >= 12 ? 'PM' : 'AM';
            $displayHour = $hour % 12 === 0 ? 12 : $hour % 12;
            $labels[] = sprintf('%02d:00 %s', $displayHour, $meridiem);

            $values[] = isset($orders[$hour]) && $total_orders > 0
                ? round(($orders[$hour]->count / $total_orders) * 100, 2)
                : 0;
        }

        return compact('labels', 'values', 'colors');

    }

    public function data_old($request = null)
    {

        $quadrant = request()->get('quadrant');

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

        $ordersQuery = OrderReport::
            whereBetween("order_reports.final_status_at", [$startDate, $endDate])
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->when($quadrant, function ($query) use ($quadrant) {
                $query
                    ->where(function ($q) use ($quadrant) {
                        $q->where('shop_region.quadrant_id', $quadrant);
                    });
            })
            ->belongsToMe()->excludeQuadrants('shop_region.quadrant_id');

        // Fetch hourly order counts
        $orders = $ordersQuery
            ->toBase()
            ->selectRaw('HOUR(order_reports.final_status_at) AS hour, COUNT(*) AS count')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        $loop   = true;
        $start  = 0;
        $index  = 0;
        $labels = [];
        $values = [];

        $total_orders = $orders->sum('count');

        while ($loop) {

            $meridiem = $start > 11 ? 'PM' : 'AM';
            $time     = $start > 12 ? $start - 12 : $start;

            $labels[] = sprintf("%'.02d:00 %s", $time, $meridiem);

            if (isset($orders[$index]) && $orders[$index]->hour == $start) {
                $values[] = round(($orders[$index]->count / $total_orders) * 100, 2);
                $index++;
            } else {
                $values[] = 0;
            }
            if ($start == 23) {
                $loop = false;
            }
            $start++;
        }

        return compact('labels', 'values');
    }
}
