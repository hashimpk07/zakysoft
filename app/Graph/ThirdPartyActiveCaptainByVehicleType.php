<?php
namespace App\Graph;

use App\Captain;
use App\vehicle_type;

class ThirdPartyActiveCaptainByVehicleType implements Graph
{
    public function data()
    {
        $company_id_3pl = session('company_id_3pl') ?? request('company_id_3pl');
        $colors = [
            '#22c55e',
            '#10b981',
            '#14b8a6',
            '#06b6d4',
            '#0ea5e9',
            '#3b82f6',
            '#6366f1',
            '#8b5cf6',
            '#a855f7',
            '#10b981',
            '#f97316',
            '#ef4444',
            '#84cc16',
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
            '#22c55e',
        ];

        $labels = [];
        $values = [];

        $captains_by_vehicle = [];
        $vehicle_types = vehicle_type::all();
        foreach ($vehicle_types as $type) {
            $captains = Captain::active()
                ->belongsTo3pl($company_id_3pl)
                ->whereHas(
                    'vehicle.vehicleType',
                    function ($query) use ($type) {
                        $query->where('id', $type->id);
                    }
                )->excludeQuadrants()->count();

            $labels[] = $type->name;
            $values[] = $captains;
        }

        return compact('colors', 'labels', 'values');
    }
}
