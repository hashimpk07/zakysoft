<?php

namespace App\Interfaces\Mobile;

use App\Captain;
use App\ShiftStatus;
use App\Vehicle;

interface ShiftInterface{
    public function getCaptainAssignedVehicle(int $assignee): ?Vehicle;

    public function updateCaptainShiftStatus(int $captainId, array $data): bool;

    public function createShiftStatus(array $data): ShiftStatus;

    public function getExistingShiftStatus(int $captainId): bool;
    public function getCaptainWithCurrentOrder(int $captain_id): ?Captain;
    public function updateCurrentShift(int $captainId, array $data);
}