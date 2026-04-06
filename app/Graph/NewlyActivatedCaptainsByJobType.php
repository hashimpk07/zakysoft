<?php
namespace App\Graph;

use App\Captain;
use App\CaptainEmploymentType;
use Carbon\Carbon;

class NewlyActivatedCaptainsByJobType implements Graph
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

        $toDate = request()->has('to_date')
        ? Carbon::parse(request()->get('to_date'))->endOfDay()
        : now()->endOfDay();

        $startDate = $toDate->copy()->startOfMonth();
        $months    = [];

        for ($i = 2; $i >= 0; $i--) {
            $currentDate = $startDate->copy()->subMonths($i);

            if (! isset($months[$currentDate->format('M')])) {
                $months[$currentDate->format('M')] = [
                    'name'  => $currentDate->format('M'),
                    'start' => $currentDate->copy()->startOfMonth()->toDateTimeString(),
                    'end'   => $currentDate->copy()->endOfMonth()->toDateTimeString(),
                ];
            }
        }

        $months = array_values($months);

        $quadrant = request()->get('quadrant');
        // Fetch all employment types
        $employmentTypes = CaptainEmploymentType::toBase()->get();
        $datasets        = [];

        foreach ($employmentTypes as $employmentType) {
            $typeId   = $employmentType->id;
            $typeName = $employmentType->name;

            $counts = [];
            foreach ($months as $month) {

                $captainQuery = Captain::with('employmentType')
                    ->where('captain_employment_type_id', $typeId)
                    ->whereBetween('created_at', [$month['start'], $month['end']])
                    ->excludeQuadrants()
                    ->belongsToMe()
                    ->active();

                if ($quadrant) {
                    $captainQuery->whereHas('regions.quadrant', function ($query) use ($quadrant) { // Check if the captain's region has a specific quadrant
                        $query->where('quadrants.id', $quadrant);
                    });
                }

                $count    = $captainQuery->count();
                $counts[] = $count;
            }

            $randomColor = $colors[array_rand($colors)];

            $datasets[] = [
                'label'           => $typeName ?? 'Unknown',
                'data'            => $counts,
                'backgroundColor' => $randomColor,
                'borderWidth'     => 1,
            ];
        }

        return [
            'labels'   => array_column($months, 'name'),
            'datasets' => $datasets,
            'colors'   => $colors,
        ];

    }
}
