<?php
namespace App\Graph;

use App\Captain;
use App\CaptainEmploymentType;
use Carbon\Carbon;

class ManagementThirdPartyNewlyActivatedCaptainsByJobType implements Graph
{
    public function data()
    {
        $colors = [
            '#36a2eb',    // Keep one bright blue
            '#ff6384',    // Pink
            '#ff9f40',    // Orange
            '#ffcd56',    // Yellow
            '#14b8a6',    // Teal
            '#6b7280',    // Gray
            '#1f6f70',    // Dark teal
            '#b5d1af',    // Sage green
            '#5f4c60',    // Purple
            '#97a7c4',    // Steel blue
            '#F5921B',    // Bright orange
            '#e1a692',    // Peach
            '#9932CC',    // Dark orchid (replacing similar turquoise)
            '#dedad2',    // Light gray
            '#8B4513',    // Saddle brown (replacing similar blue)
            '#e8daff',    // Light purple
            '#ff8389',    // Light red
            '#FF1493',    // Deep pink (replacing similar turquoise)
            '#006400',    // Dark green (replacing similar sea green)
            '#4B0082',    // Indigo (replacing similar light blue)
            '#90EE90',    // Light green
            '#FF6347',    // Tomato red
        ];

        $toDate = request()->has('to_date')
            ? Carbon::parse(request()->get('to_date'))->endOfDay()
            : now()->endOfDay();

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
        // Fetch all employment types
        $employmentTypes = CaptainEmploymentType::toBase()->get();
        $datasets = [];
        $colorIndex = 0;
        foreach ($employmentTypes as $employmentType) {
            $typeId = $employmentType->id;
            $typeName = $employmentType->name;

            $counts = [];
            foreach ($months as $month) {

                $captainQuery = Captain::with('employmentType')
                    ->where('captain_employment_type_id', $typeId)
                    ->whereBetween('created_at', [$month['start'], $month['end']])
                    ->excludeQuadrants()
                    ->when($company, function ($query, $company) {
                        $query->whereHas('captainThirdParty', function ($query) use ($company) {
                            $query->where('third_party_logistic_company_id', '=', $company)->excludeCompanies();
                        });
                    })
                    ->belongsToMe()
                    ->active();

                if ($quadrant) {
                    $captainQuery->whereHas('regions.quadrant', function ($query) use ($quadrant) { // Check if the captain's region has a specific quadrant
                        $query->where('quadrants.id', $quadrant);
                    });
                }

                $count = $captainQuery->count();
                $counts[] = $count;
            }

            $color = $colors[$colorIndex % count($colors)]; // Use modulo to cycle through colors if more types than colors
            $colorIndex++;

            $datasets[] = [
                'label' => $typeName ?? 'Unknown',
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
