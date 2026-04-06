<?php
namespace App\Graph;

use App\OrderReport;

class OrdersGrowthRateComparison implements Graph
{

    public function transformChartData($current_year_data, $previous_year_data, $startMonth, $endMonth)
    { 

        $labels       = [];
        $valuesByYear = [];
        $growth_rates = [];

        // Combine current and previous year data into a single collection
        $allData = collect([$current_year_data, $previous_year_data])->flatten();

        // Dynamically get unique years from the data
        $years = $allData->pluck('year')->unique()->sort()->values()->toArray();
        //dd($years);

        // Map month numbers to names
        $monthNames = [
            1  => 'January', 2  => 'February', 3  => 'March',
            4  => 'April', 5    => 'May', 6       => 'June',
            7  => 'July', 8     => 'August', 9    => 'September',
            10 => 'October', 11 => 'November', 12 => 'December',
        ];
        // Determine the months to include in the chart
        $filteredMonths = ($startMonth > $endMonth)
        ? array_merge(range($startMonth, 12), range(1, $endMonth))
        : range($startMonth, $endMonth);

// Create labels based on selected months
        foreach ($filteredMonths as $monthNumber) {
            $labels[] = $monthNames[$monthNumber];
        }

        // Organize data by year and month
        foreach ($years as $year) {
            $valuesByYear[$year] = [];

            foreach ($filteredMonths as $monthNumber) {
                // Find the first matching record for the given year and month
                $record = $allData->firstWhere(fn($data) => $data->year == $year && $data->month == $monthNumber);

                // Store the count value (default to 0 if no record exists)
                $valuesByYear[$year][] = $record->count ?? 0;
            }
        }

        $growth_rates = [];

        foreach ($filteredMonths as $index => $monthNumber) {
            // Get the current year and previous year for comparison
            $currentYear = $years[count($years) - 1]; // Latest year (e.g., 2024)
            $previousYear = $years[count($years) - 2]; // Previous year (e.g., 2023)
    
            // If the current year's data is missing for this month, compare the previous year with the year before that
            if (($valuesByYear[$currentYear][$index] ?? 0) === 0) {
                $currentYear = $previousYear;
                $previousYear = $previousYear = count($years) >= 3 ? $years[count($years) - 3] : null; // Year before the previous year (e.g., 2022)
            }
    
            $currentCount = $valuesByYear[$currentYear][$index] ?? 0;
            $previousCount = $valuesByYear[$previousYear][$index] ?? 0;
    
            // Calculate growth rate
            $growthRate = $previousCount > 0
                ? (($currentCount - $previousCount) / $previousCount) * 100
                : ($previousCount === 0 && $currentCount > 0 ? 100 : 0);
    
            $growth_rates[] = round($growthRate, 2);
        }

        return [
            'labels'         => $labels,
            'years'          => $years,
            'values_by_year' => $valuesByYear,
            'growth_rates'   => $growth_rates,
            'colors'         => [
                '#36a2eb', '#ff6384', '#ff9f40', '#ffcd56',
                '#14b8a6', '#6b7280', '#1f6f70', '#b5d1af',
                '#5f4c60', '#97a7c4', '#F5921B', '#e1a692',
                '#54bebe', '#dedad2', '#badbdb', '#e8daff',
                '#ff8389', '#3ddbd9', '#20B2AA', '#ADD8E6',
                '#90EE90', '#FF6347',
            ],
        ];


    }

    public function data()
    {

        $quadrant = request()->get('quadrant');

        $end_date   = now()->parse(request('to_date', now()->format('Y-m-d')));
        $start_date = $end_date->copy()->subMonths(5);

        $current_year_data = OrderReport::query()
            ->whereBetween('order_reports.final_status_at', [$start_date->startOfMonth(), $end_date->endOfMonth()])
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
            ->groupByRaw('month, year')
            ->orderBy('year', 'asc')
            ->orderBy('month', 'asc')
            ->finishedOrders()
            ->excludeQuadrants('shop_region.quadrant_id')
            ->belongsToMe()
            ->get();

        $previous_year_data = OrderReport::query()
            ->whereBetween('order_reports.final_status_at', [$start_date->subYear()->startOfMonth(), $end_date->subYear()->endOfMonth()])
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
            ->groupByRaw('month, year')
            ->orderBy('year', 'asc')
            ->orderBy('month', 'asc')
            ->finishedOrders()
            ->excludeQuadrants('shop_region.quadrant_id')
            ->belongsToMe()
            ->get();

        $startMonth = $start_date->month;
        $endMonth   = $end_date->month;

        $chartData = $this->transformChartData($current_year_data, $previous_year_data, $startMonth, $endMonth);

        return $chartData;
    }

}
