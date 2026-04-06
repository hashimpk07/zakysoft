<?php

namespace App\Services\General\Reports;

use App\Interfaces\General\VehicleFleetReportsInterface;
use App\VehicleFleetIssues;


final class VehicleFleetService
{
   
    public function __construct(protected readonly VehicleFleetReportsInterface $interface) {}

    public function getVehicleFleetList($search, int $perPage)
    {
        $captains = $this->interface->getCaptainsVehicleFleets($search,$perPage);
        $statics = $this->interface->getVehicleFleetStatics();

        $counts = [
            'fuel_usage'   => $statics[VehicleFleetIssues::REFUEL]->received_amount ?? 0,
            'oil_change'   => $statics[VehicleFleetIssues::OIL_CHANGE]->received_amount ?? 0,
            'filter_change'=> $statics[VehicleFleetIssues::FILTER_CHANGE]->received_amount ?? 0,
            'new_battery'  => $statics[VehicleFleetIssues::NEW_BATTERY]->received_amount ?? 0,
            'new_tyre'     => $statics[VehicleFleetIssues::NEW_TIRE]->received_amount ?? 0,
            'puncture'     => $statics[VehicleFleetIssues::TYRE_PUNCTURE]->received_amount ?? 0,
            'other_exp'    => $statics[VehicleFleetIssues::OTHER_EXP]->received_amount ?? 0,
        ];

        return [
            'captains' => $captains,
            'statics' => $counts
        ];
    }

    public function getVehicleFleetDetails($id)
    {
        return $this->interface->getVehicleFleetDetails($id);
    }
}
