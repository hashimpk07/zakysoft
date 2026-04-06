<?php
namespace App\Graph;

use App\OrderReport;
use Illuminate\Support\Facades\DB;

class ManagementThirdPartyWeeklyOrders implements Graph
{
    public function data()
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
        $labels = [];
        $values = [];

        $to_date = request()->has('to_date') ? now()->parse(request()->get('to_date'))->endOfDay() : now()->endOfDay();
        $from_date = request()->has('from_date') ? now()->parse(request()->get('from_date'))->startOfDay() : null;

        if ($from_date && $from_date->isSameDay($to_date)) {
            $from_date = $to_date->copy()->subDays(6)->startOfDay();
        } elseif (!$from_date) {
            $from_date = $to_date->copy()->subDays(6)->startOfDay();
        }

        //This is for showing the date range in the dashboard
        $fromDateTime = \Carbon\Carbon::parse($from_date)->setTime(6, 0, 0);
        $toDateTime =  \Carbon\Carbon::parse($to_date)->addDay()->setTime(5, 59, 59);

        $isToday = $from_date->isToday() && $to_date->isToday();

        $quadrant = request()->get('quadrant');

        $company = request()->get('company');

        // Query to get orders count grouped by date
        $query = OrderReport::belongsToMe()
            ->select(
                DB::raw('count(*) as no_of_orders'),
                //DB::raw('DAYNAME(order_reports.final_status_at) as weekday')
                DB::raw('DAYNAME(DATE_SUB(order_reports.final_status_at, INTERVAL 6 HOUR)) as weekday') // Business day grouping
            )
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->excludeQuadrants('shop_region.quadrant_id')
            ->finishedOrders()
            ->when($company, function ($query, $company) {
                $query->whereHas('captain.captainThirdParty', function ($query) use ($company) {
                    $query->where('third_party_logistic_company_id', '=', $company)->excludeCompanies();
                });
            })
            //->whereBetween(DB::raw('DATE(order_reports.final_status_at)'), [$from_date, $to_date])
            ->whereBetween(DB::raw('order_reports.final_status_at'), [
                $fromDateTime->format('Y-m-d'),
                $toDateTime->format('Y-m-d')
            ]) // Business day filtering
            ->groupByRaw('weekday');

        if ($quadrant) {
            $query->where('shop_region.quadrant_id', $quadrant);
        }

        $data = $query->get()->keyBy('weekday');

        // Count occurrences of each weekday in the selected date range
        $weekdayCounts = [];
        $start = $from_date->copy();
        while ($start->lte($to_date)) {
            $weekday = $start->format('l'); // Full day name (e.g., "Monday")
            $weekdayCounts[$weekday] = ($weekdayCounts[$weekday] ?? 0) + 1;
            $start->addDay();
        }

        // Prepare labels and values
        $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $todayIndex = now()->dayOfWeek;
        $weekdays = array_merge(array_slice($weekdays, $todayIndex), array_slice($weekdays, 0, $todayIndex));

        foreach ($weekdays as $day) {
            $labels[] = substr($day, 0, 3); // 'Mon', 'Tue', etc.
            $totalOrders = $data[$day]->no_of_orders ?? 0;

            if ($isToday) {
                $values[] = $totalOrders;
            } else {
                $daysCount = $weekdayCounts[$day] ?? 1;
                $values[] = round($totalOrders / $daysCount, );
            }
        }
  
        return [
            'colors' => $colors,
            'labels' => array_reverse($labels),
            'values' => array_reverse($values),
        ];
    }
}
