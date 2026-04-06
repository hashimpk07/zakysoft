<?php

namespace App\Interfaces\General;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;


interface VehicleFleetReportsInterface
{
    public function getCaptainsVehicleFleets($perPage);
    public function getVehicleFleetStatics();
    public function getVehicleFleetDetails($id);
}