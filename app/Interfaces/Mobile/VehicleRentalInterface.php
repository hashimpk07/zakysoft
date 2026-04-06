<?php

namespace App\Interfaces\Mobile;

use App\Captain;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface VehicleRentalInterface{
    public function getVehicleRentalStatistics(Captain $captain, Request $request): Collection;
    public function getVehicleRentalList(Captain $captain, Request $request): Collection;

    public function getVehicleRentalTransactions(Captain $captain, int $perPage): ?LengthAwarePaginator;
}