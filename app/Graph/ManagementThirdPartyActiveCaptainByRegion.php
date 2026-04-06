<?php
namespace App\Graph;

use App\Captain;
use App\Quadrant;

class ManagementThirdPartyActiveCaptainByRegion implements Graph
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

        $labels      = [];
        $values      = [];
        $total_count = 0;

        $quadrant = request()->get('quadrant');
        $company  = request()->get('company');

        $quadrants = $quadrant ? Quadrant::where('id', $quadrant)->toBase()->get() : Quadrant::excludeQuadrants()->toBase()->get();

        foreach ($quadrants as $region) {
            $region_id = $region->id;
            $captains  = Captain::with('regions.quadrant')
                ->when($company, function ($query, $company) {
                    $query->whereHas('captainThirdParty', function ($query) use ($company) {
                        $query->where('third_party_logistic_company_id', '=', $company)->excludeCompanies();
                    });
                })
                ->whereHas('regions.quadrant', function ($query) use ($region_id) {
                    $query->where('id', $region_id);
                })->active()->excludeQuadrants()->belongsToMe()->count();

            $total_count += $captains;
            $count[]  = $captains;
            $labels[] = $region->name;
            $values[] = $captains;
        }

        return compact('colors', 'labels', 'values', 'total_count');
    }
}
