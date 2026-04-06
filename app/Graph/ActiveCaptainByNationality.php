<?php
namespace App\Graph;

use App\Captain;

class ActiveCaptainByNationality implements Graph
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

        $nationalityCounts = Captain::with('nationality')
            ->active()
            ->belongsToMe()
            ->excludeQuadrants()
            ->get()
            ->groupBy(function ($captain) {
                return $captain->nationality->name ?? 'NOT SPECIFIED';
            })
            ->map(function ($group) {
                return $group->count(); // Count captains for each nationality
            })
            ->toArray();

        $totalCaptains = Captain::active()->belongsToMe()->excludeQuadrants()->count();

        $country_data = [['Country', 'Count of Captains']]; // Initialize with headers
        foreach ($nationalityCounts as $nationality => $count) {
            $country_data[] = [$nationality, $count];
        }

        return response()->json([
            'country_data'         => $country_data,
            'total_captains_count' => $totalCaptains,
            'colors'               => $colors,
        ]);

    }
}
