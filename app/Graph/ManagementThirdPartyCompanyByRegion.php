<?php
namespace App\Graph;

use App\Quadrant;
use App\ThirdPartyLogisticCompany;

class ManagementThirdPartyCompanyByRegion implements Graph
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

        // $quadrants = $quadrant ? Quadrant::where('id', $quadrant)->toBase()->get() : Quadrant::excludeQuadrants()->toBase()->get();

        $regions = Quadrant::excludeQuadrants()->get();

        $regionsWithCompanies = ThirdPartyLogisticCompany::with('regions')
            ->active()
            ->excludeCompanies()
            ->get()
            ->groupBy(function ($company) {
                return $company->regions->pluck('name')->first(); // Assuming you're grouping by the first region name
            });

        $total_count = 0;
        $labels      = [];
        $values      = [];
        $count       = [];

        foreach ($regions as $region) {
            $regionName        = $region->name;
            $companiesInRegion = $regionsWithCompanies[$regionName] ?? collect(); // Handle case where no companies are assigned to a region

            // Get count of companies for this region
            $companyCount = $companiesInRegion->count();
            $total_count += $companyCount;

            // Store region data for the output
            $labels[] = $regionName;
            $values[] = $companyCount;
            $count[]  = $companiesInRegion->pluck('name')->toArray(); // Convert to array for better readability
        }

      

        return compact('colors', 'labels', 'values', 'total_count');
    }
}
