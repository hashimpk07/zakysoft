<?php
namespace App\Graph;

use App\Captain;
use App\Quadrant;

class ActiveOnlineCaptainByRegion implements Graph
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

        $quadrant = request()->get('quadrant');

        $quadrants = $quadrant ? Quadrant::where('id', $quadrant)->toBase()->get() : Quadrant::excludeQuadrants()->toBase()->get();

        $onlineCounts = [];
        $offlineCounts = [];
        $labels = [];
        foreach ($quadrants as $region) {
            $region_id = $region->id;
            // Get online captains count for the region
            $onlineCount = Captain::with('regions.quadrant')
                ->whereHas('regions.quadrant', function ($query) use ($region_id) {
                    $query->where('id', $region_id);
                })
                ->excludeQuadrants()
                ->belongsToMe()
                ->active()
                ->online() // Online captains
                ->count();

            // Get offline captains count for the region
            $offlineCount = Captain::with('regions.quadrant')
                ->whereHas('regions.quadrant', function ($query) use ($region_id) {
                    $query->where('id', $region_id);
                })
                ->belongsToMe()
                ->excludeQuadrants()
                ->active()
                ->offline() // Offline captains
                ->count();

            $onlineCounts[] = $onlineCount;
            $offlineCounts[] = $offlineCount;
            $labels[] = $region->name;
        }

        return [
            'online' => $onlineCounts,
            'labels' => $labels,
            'offline' => $offlineCounts,
            'colors' => $colors,
        ];

    }
}
