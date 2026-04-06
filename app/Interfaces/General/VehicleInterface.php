<?php

namespace App\Interfaces\General;

use App\Captain;
use App\Vehicle;
use App\VehicleCaptain;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

interface VehicleInterface
{
    public function statistics(): array;
    public function filter(Request $request, int $perPage = 10): LengthAwarePaginator;
    public function getImages(int $vehicleId);
    public function isCaptainAssigned(int $captainId): bool;

    public function findAssignedToCaptain(int $captainId): ?Vehicle;

    public function deactivateCurrentAssignment(int $vehicleId): void;
    public function assignToCaptain(int $vehicleId, int $captainId, Captain $captain): VehicleCaptain;

    public function detachCaptain(Vehicle $vehicle): void;
    public function create(array $data): Vehicle;

    public function update(Vehicle $vehicle, array $data): Vehicle;

    public function createCaptainAssignment(array $data): VehicleCaptain;

    public function syncExpiryReminder(string $detail, string $date, string $name, int $vehicleId): void;
}
