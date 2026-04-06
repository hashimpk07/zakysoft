<?php
namespace App\Graph;

use App\Captain;
use App\vehicle_type;

class ActiveCaptainByVehicleType implements Graph
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

        $quadrant            = request()->get('quadrant');
        $captains_by_vehicle = [];
        $vehicle_types       = vehicle_type::all();

        $captainsWithoutVehicle = Captain::belongsToMe()
            ->excludeQuadrants()
            ->active()
            ->when($quadrant, function ($query) use ($quadrant) {
                $query->whereHas('regions.quadrant', function ($query) use ($quadrant) {
                    $query->where('id', $quadrant);
                });
            })
            ->doesntHave('vehicle.vehicleType')
            ->count();

        $labels[] = 'No Vehicle Assigned';
        $values[] = $captainsWithoutVehicle;

        foreach ($vehicle_types as $type) {
            $captainsQuery = Captain::belongsToMe()->excludeQuadrants()->active()->whereHas('vehicle.vehicleType', function ($query) use ($type) {
                $query->where('id', $type->id);
            });

            if ($quadrant) {
                $captainsQuery->whereHas('regions.quadrant', function ($query) use ($quadrant) {
                    $query->where('id', $quadrant);
                });
            }

            $captains = $captainsQuery->count();

            $labels[] = $type->name;
            $values[] = $captains;
        }

        return compact('colors', 'labels', 'values');
    }
}
