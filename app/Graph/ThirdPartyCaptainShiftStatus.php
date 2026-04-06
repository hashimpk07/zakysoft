<?php
namespace App\Graph;

use App\Captain;

class ThirdPartyCaptainShiftStatus implements Graph
{
    public function data()
    {
        $company_id_3pl = session('company_id_3pl') ?? request("company_id_3pl");
        $colors = [
            '#22c55e',
            '#FF7F7F',
        ];

        $labels = [];
        $values = [];

        $captains_by_vehicle = [];

        $online_captains = Captain::active()->online()
            ->excludeQuadrants()
            ->belongsTo3pl($company_id_3pl)
            ->count();

        $labels[] = 'Online Captain';
        $values[] = $online_captains;

        $offline_captains = Captain::active()->offline()
            ->excludeQuadrants()
            ->belongsTo3pl($company_id_3pl)
            ->count();

        $labels[] = 'Offline Captain';
        $values[] = $offline_captains;

        return compact('colors', 'labels', 'values');
    }
}
