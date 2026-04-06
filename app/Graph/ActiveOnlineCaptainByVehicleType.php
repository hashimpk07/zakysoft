<?php
namespace App\Graph;

use App\Captain;
use App\Quadrant;
use App\vehicle_type;

class ActiveOnlineCaptainByVehicleType implements Graph
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

        $vehicle_types = vehicle_type::all();

        $quadrants = $quadrant ? Quadrant::where('id', $quadrant)->toBase()->get() : Quadrant::excludeQuadrants()->toBase()->get();

        $onlineData = [];
        $offlineData = [];
        $labels = $vehicle_types->pluck('name')->toArray(); // Vehicle types as labels

        foreach ($vehicle_types as $type) {
            $onlineCount = 0;
            $offlineCount = 0;

            foreach ($quadrants as $region) {
                $region_id = $region->id;

                $onlineCount += Captain::with('regions.quadrant')
                    ->whereHas('regions.quadrant', function ($query) use ($region_id) {
                        $query->where('id', $region_id);
                    })
                    ->excludeQuadrants()
                    ->belongsToMe()
                    ->active()
                    ->online()
                    ->whereHas('vehicle.vehicleType', function ($query) use ($type) {
                        $query->where('id', $type->id);
                    })
                    ->count();

                $offlineCount += Captain::with('regions.quadrant')
                    ->whereHas('regions.quadrant', function ($query) use ($region_id) {
                        $query->where('id', $region_id);
                    })
                    ->belongsToMe()
                    ->excludeQuadrants()
                    ->active()
                    ->offline()
                    ->whereHas('vehicle.vehicleType', function ($query) use ($type) {
                        $query->where('id', $type->id);
                    })
                    ->count();
            }

            $onlineData[] = $onlineCount;
            $offlineData[] = $offlineCount;
        }

        return [
            'online' => $onlineData,
            'offline' => $offlineData,
            'labels' => $labels,
            'vehicle_types' => $vehicle_types->pluck('name'),
            'colors' => $colors,
        ];

    }
}
