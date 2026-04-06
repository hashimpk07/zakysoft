<?php

namespace App\Interfaces\General;

use App\Vehicle;

interface RentedVehicleInterface
{
    public function getRentedVehicles(array $filters, int $perPage = 20);
    public function countRentedByType(int $type): int;
    public function getTotalRents(): float;
    public function getReceivedRents(): float;
    public function getVehicleCaptain(Vehicle $vehicle);
    public function getVehicleRentsWithSettlement(int $vehicleId, int $perPage = 20);
    public function getSettlementsByVehicleAndDateRange(Vehicle $vehicle, string $from, string $to);
    public function createSettlement(Vehicle $vehicle, array $data);



}
