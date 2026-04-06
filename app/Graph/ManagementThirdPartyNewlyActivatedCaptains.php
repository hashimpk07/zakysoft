<?php
namespace App\Graph;

use App\Captain;
use App\Quadrant;
use Carbon\Carbon;

class ManagementThirdPartyNewlyActivatedCaptains implements Graph
{
    public function data()
    {
        $colors = [
            '#36a2eb', '#ff6384', '#ff9f40', '#ffcd56', '#14b8a6', '#6b7280',
            '#1f6f70', '#b5d1af', '#5f4c60', '#97a7c4', '#F5921B', '#e1a692',
            '#54bebe', '#dedad2', '#badbdb', '#e8daff', '#ff8389', '#3ddbd9',
            '#20B2AA', '#ADD8E6', '#90EE90', '#FF6347',
        ];

        $toDate = request()->has('to_date')
            ? Carbon::parse(request()->get('to_date'))
            : now();

        $startDate = $toDate->copy()->startOfMonth();
        $months = [];

        // Loop through last 3 business months
        for ($i = 2; $i >= 0; $i--) {
            $currentMonth = $startDate->copy()->subMonths($i);
            $monthKey = $currentMonth->format('M');

            if (!isset($months[$monthKey])) {
                $start = $currentMonth->copy()->startOfMonth()->setTime(6, 0, 0); // Start at 6 AM
                $end = $currentMonth->copy()->addMonthNoOverflow()->startOfMonth()->setTime(5, 59, 59); // End at next month's 5:59:59 AM

                $months[$monthKey] = [
                    'name' => $monthKey,
                    'start' => $start->toDateTimeString(),
                    'end' => $end->toDateTimeString(),
                ];
            }
        }

        $months = array_values($months);

        $quadrant = request()->get('quadrant');
        $company = request()->get('company');

        $quadrants = $quadrant
            ? Quadrant::where('id', $quadrant)->toBase()->get()
            : Quadrant::excludeQuadrants()->toBase()->get();

        $datasets = [];
        $colorIndex = 0;

        foreach ($quadrants as $region) {
            $region_id = $region->id;
            $regionName = $region->name;

            $counts = [];
            foreach ($months as $month) {
                $count = Captain::with('regions.quadrant')
                    ->whereHas('regions.quadrant', function ($query) use ($region_id) {
                        $query->where('quadrants.id', $region_id);
                    })
                    ->when($company, function ($query) use ($company) {
                        $query->whereHas('captainThirdParty', function ($query) use ($company) {
                            $query->where('third_party_logistic_company_id', '=', $company)->excludeCompanies();
                        });
                    })
                    ->excludeQuadrants()
                    ->whereBetween('created_at', [$month['start'], $month['end']]) // business month range
                    ->belongsToMe()
                    ->active()
                    ->count();

                $counts[] = $count;
            }

            $datasets[] = [
                'label' => $regionName,
                'data' => $counts,
                'backgroundColor' => $colors[$colorIndex++ % count($colors)],
                'borderWidth' => 1,
            ];
        }

        return [
            'labels' => array_column($months, 'name'),
            'datasets' => $datasets,
            'colors' => $colors,
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

        $toDate = request()->has('to_date')
            ? Carbon::parse(request()->get('to_date'))->endOfDay()
            : now()->endOfDay();

        $startDate = $toDate->copy()->startOfMonth();
        $months = [];

        for ($i = 2; $i >= 0; $i--) {
            $currentDate = $startDate->copy()->subMonths($i);

            if (!isset($months[$currentDate->format('M')])) {
                $months[$currentDate->format('M')] = [
                    'name' => $currentDate->format('M'),
                    'start' => $currentDate->copy()->startOfMonth()->toDateTimeString(),
                    'end' => $currentDate->copy()->endOfMonth()->toDateTimeString(),
                ];
            }
        }

        $months = array_values($months);

        $quadrant = request()->get('quadrant');

        $company = request()->get('company');

        $quadrants = $quadrant ? Quadrant::where('id', $quadrant)->toBase()->get() : Quadrant::excludeQuadrants()->toBase()->get();

        $datasets = [];
        $colorIndex = 0;
        foreach ($quadrants as $region) {
            $region_id = $region->id;
            $regionName = $region->name;

            $counts = [];
            foreach ($months as $month) {
                $count = Captain::with('regions.quadrant')
                    ->whereHas('regions.quadrant', function ($query) use ($region_id) {
                        $query->where('quadrants.id', $region_id);
                    })
                    ->when($company, function ($query) use ($company) {
                        $query->whereHas('captainThirdParty', function ($query) use ($company) {
                            $query->where('third_party_logistic_company_id', '=', $company)->excludeCompanies();
                        });
                    })
                    ->excludeQuadrants()
                    ->whereBetween('created_at', [$month['start'], $month['end']])
                    ->belongsToMe()
                    ->active()
                    ->count();

                $counts[] = $count;
            }

            $color = $colors[$colorIndex % count($colors)]; // Use modulo to cycle through colors if more types than colors
            $colorIndex++;
            $datasets[] = [
                'label' => $regionName,
                'data' => $counts,
                'backgroundColor' => $color,
                'borderWidth' => 1,
            ];
        }

        return [
            'labels' => array_column($months, 'name'),
            'datasets' => $datasets,
            'colors' => $colors,
        ];

    }
}
