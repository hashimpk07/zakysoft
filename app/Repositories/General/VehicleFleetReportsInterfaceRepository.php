<?php

namespace App\Repositories\General;
use App\Interfaces\General\VehicleFleetReportsInterface;

use App\VehicleFleet;
use App\Captain;



class VehicleFleetReportsInterfaceRepository implements VehicleFleetReportsInterface
{

    public function getCaptainsVehicleFleets($perPage = 20)
    {
        return Captain::query()
            ->with(['regions', 'vehicle.vehicleType'])
            ->withSum('pendingVehicleFleets', 'amount')
            ->withSum('acceptedVehicleFleets', 'amount')
            ->active()
            ->paginate($perPage);
    }

    public function getVehicleFleetStatics()
    {
        return VehicleFleet::query()
            ->received()
            ->groupBy('vehicle_fleet_issue_id')
            ->selectRaw('vehicle_fleet_issue_id, sum(amount) as received_amount')
            ->get()
            ->keyBy('vehicle_fleet_issue_id');
    }

    public function getVehicleFleetDetails($captainId)
    {
        return Captain::query()
            ->select('id','firstname','lastname')
            ->with([
                'vehicle:id,assigned_to,type,number,current_km',
                'vehicle.vehicleType:id,name',
                'vehicleFleets.issue.category:id,name',
                'vehicleFleets.issue:id,name,vehicle_fleet_category_id',
                'vehicleFleets.vehicle.vehicleType:id,name',
                'vehicleFleets.vehicle:id,number,type',
                'vehicleFleets.status:id,name',
                'vehicleFleets.transaction.user:id,name'
            ])
            ->withSum('pendingVehicleFleets','amount')
            ->withSum('acceptedVehicleFleets','amount')
            ->withSum('receivedVehicleFleets','amount')
            ->findOrFail($captainId);

    }
}    