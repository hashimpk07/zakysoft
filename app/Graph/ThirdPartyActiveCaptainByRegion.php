<?php
namespace App\Graph;

use App\Captain;
use App\Quadrant;

class ThirdPartyActiveCaptainByRegion implements Graph
{
    public function data()
    {
        $company_id_3pl = session('company_id_3pl') ?? request('company_id_3pl');
        $colors = [
            '#10b981',
            '#f97316',
            '#ef4444',
            '#84cc16',
            '#22c55e',
            '#10b981',
            '#14b8a6',
            '#06b6d4',
            '#0ea5e9',
            '#3b82f6',
            '#6366f1',
            '#8b5cf6',
            '#a855f7',
            '#d946ef',
            '#ec4899',
            '#f43f5e',
            '#1e40af',
            '#6b21a8',
            '#831843',
            '#9f1239',
            '#3b0764',
            '#9333ea',
            '#0c4a6e',
            '#22c55e'
        ];

        $labels = [];
        $values = [];

        $quadrants = Quadrant::excludeQuadrants()->toBase()->get();
        foreach ($quadrants as $region) {
            $region_id = $region->id;
            $captains = Captain::with('regions.quadrant')
                ->belongsTo3pl($company_id_3pl)
                ->whereHas('regions.quadrant', function ($query) use ($region_id) {
                    $query->where('id', $region_id);
                })->active()->excludeQuadrants()->count();
            $labels[] = $region->name;
            $values[] = $captains;
        }

        return compact('colors', 'labels', 'values');
    }
}