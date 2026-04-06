<?php
namespace App\Graph;

use App\Captain;

class ActiveCaptainByJobCategory implements Graph
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

        $quadrant = request()->get('quadrant');

        $captainsByEmploymentType = Captain::with('employmentType')
            ->selectRaw('COUNT(*) AS count, captain_employment_type_id')
            ->active()
            ->excludeQuadrants()
            ->belongsToMe()
            ->groupBy('captain_employment_type_id');

        if ($quadrant) {
            $captainsByEmploymentType->whereHas('regions.quadrant', function ($query) use ($quadrant) {
                $query->where('id', $quadrant);
            });
        }

        $captainsByEmploymentType = $captainsByEmploymentType->get();

        $noEmploymentTypeCount = 0;

        foreach ($captainsByEmploymentType as $captain) {
            if ($captain->employmentType === null) {
                $noEmploymentTypeCount += $captain->count;
            } else {
                $labels[] = $captain->employmentType->name;
                $values[] = $captain->count;
            }
        }

        if ($noEmploymentTypeCount > 0) {
            $labels[] = 'Employment Type Not Set';
            $values[] = $noEmploymentTypeCount;
        }

        return compact('colors', 'labels', 'values');
    }
}
