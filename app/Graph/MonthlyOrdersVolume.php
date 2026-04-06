<?php
namespace App\Graph;

use App\OrderReport;
use Carbon\Carbon;

class MonthlyOrdersVolume implements Graph
{
    public function data()
    {

        $endDate = Carbon::parse(request('to_date', now()->format('Y-m-d')))->endOfMonth();

        // Start date is 6 months before
        $startDate = $endDate->copy()->subMonths(5)->startOfMonth();

        $quadrant = request()->get('quadrant');

        $orderReportsData = OrderReport::query()
            ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->when($quadrant, function ($query) use ($quadrant) {
                $query
                    ->where(function ($q) use ($quadrant) {
                        $q->WhereRaw('shop_region.quadrant_id = ?', [$quadrant]);
                    });
            })
            ->selectRaw('
        COUNT(order_reports.id) as count,
        MONTH(order_reports.final_status_at) as month,
        YEAR(order_reports.final_status_at) as year
        ')
            ->groupByRaw('year, month')
            ->orderBy('year', 'asc')
            ->orderBy('month', 'asc')
            ->finishedOrders()
            ->excludeQuadrants('shop_region.quadrant_id')
            ->belongsToMe()
            ->get();

        $labels        = [];
        $orderCounts   = [];
        $growthRates   = [];
        $previousCount = null;

        foreach ($orderReportsData as $data) {
            $monthName     = Carbon::create()->month($data->month)->format('F');
            $labels[]      = $monthName;
            $orderCounts[] = $data->count;

            // Calculate growth rate
            if ($previousCount !== null && $previousCount != 0) {
                $growthRate    = (($data->count - $previousCount) / $previousCount) * 100;
                $growthRates[] = round($growthRate, 2);
            } else {
                $growthRates[] = 0; // First month has no growth rate
            }

            $previousCount = $data->count;
        }

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
        return [
            'labels'       => $labels,
            'values'       => $orderCounts,
            'growth_rates' => $growthRates,
            'colors'       => $colors,
        ];

    }
}
