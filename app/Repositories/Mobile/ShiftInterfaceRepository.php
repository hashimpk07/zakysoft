<?php

namespace App\Repositories\Mobile;

use App\Captain;
use App\Interfaces\Mobile\ShiftInterface;
use App\ShiftStatus;
use App\Vehicle;

class ShiftInterfaceRepository implements ShiftInterface
{
    public function getCaptainAssignedVehicle(int $assignee): Vehicle
    {
        return Vehicle::where('assigned_to', $assignee)->firstOrFail();
    }

    public function updateCaptainShiftStatus(int $captainId, array $data): bool
    {
        return ShiftStatus::where('captain_id', $captainId)->whereNull('shift_end')->update($data);
    }

    public function createShiftStatus(array $data): ShiftStatus
    {
        return ShiftStatus::create($data);
    }

    public function getExistingShiftStatus(int $captainId): bool
    {
        return ShiftStatus::where('captain_id', $captainId)->whereNull('shift_end')->exists();
    }
    public function getCaptainWithCurrentOrder(int $captain_id): ?Captain
    {
        return Captain::with('currentOrder')->find($captain_id);
    }

    public function updateCurrentShift(int $captainId, array $data)
    {
        $shiftStatus = ShiftStatus::where('captain_id', $captainId)->whereNull('shift_end')->firstOrFail();
        return $shiftStatus->update($data);
    }
}
