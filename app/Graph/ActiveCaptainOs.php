<?php
namespace App\Graph;

use App\Captain;

class ActiveCaptainOs implements Graph
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

        $osCountQuery = Captain::
            active()
            ->excludeQuadrants()
            ->belongsToMe()
            ->selectRaw("CASE
                        WHEN device LIKE '%Android%' THEN 'Android'
                        WHEN device LIKE '%IOS%' THEN 'IOS'
                        ELSE 'Other'
                     END as os, COUNT(*) as count")
            ->groupBy('os');

        if ($quadrant) {
            $osCountQuery->whereHas('regions.quadrant', function ($query) use ($quadrant) {
                $query->where('id', $quadrant);
            });
        }

        $osCount = $osCountQuery->get()->pluck('count', 'os');

        return [
            'colors' => $colors,
            'android' => $osCount->get('Android', 0),
            'ios' => $osCount->get('IOS', 0),
            'other' => $osCount->get('Other', 0),

        ];

    }
}
