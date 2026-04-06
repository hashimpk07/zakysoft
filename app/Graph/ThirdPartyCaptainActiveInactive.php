<?php
namespace App\Graph;

use App\Captain;

class ThirdPartyCaptainActiveInactive implements Graph
{
    public function data()
    {
        $company_id_3pl = session('company_id_3pl') ?? request('company_id_3pl');
        $colors = [
            '#06b6d4',
            '#0ea5e9',
        ];

        $labels = [];
        $values = [];

        $active_captains = Captain::active()
            ->belongsTo3pl($company_id_3pl)
            ->excludeQuadrants()
            ->count();

        $labels[] = 'Active Captain';
        $values[] = $active_captains;

        $inactive_captains = Captain::inActive()
            ->belongsTo3pl($company_id_3pl)
            ->excludeQuadrants()
            ->count();

        $labels[] = 'InActive Captain';
        $values[] = $inactive_captains;

        return compact('colors', 'labels', 'values');
    }
}
