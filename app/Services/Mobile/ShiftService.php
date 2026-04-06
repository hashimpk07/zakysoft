<?php

namespace App\Services\Mobile;

use App\Interfaces\Mobile\ShiftInterface as MobileShiftInterface;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

use Illuminate\Database\Eloquent\ModelNotFoundException;

class ShiftService
{
    public function __construct(protected readonly MobileShiftInterface $shiftInterface) {}
    public function getShiftStatus($captain): array
    {
        $shift = $captain->currentShift;

        $startedAt = $shift?->shift_start ? Carbon::parse($shift->shift_start) : null;

        return [
            'has_active_shift' => (bool) $shift,
            'started_at' => $startedAt?->format('Y-m-d H:i:s'),
            'started_at_in_seconds' => $startedAt?->diffInSeconds(now(), false),
            'finished_orders' => $captain->todayFinishedOrders()->count(),
            'working_hours_in_seconds' => $captain->todayActiveSeconds(),
        ];
    }

    public function CreateShiftStatus(int $captainId)
    {
        $vehicle = $this->shiftInterface->getCaptainAssignedVehicle(assignee: $captainId);
        $vehicle_id = $vehicle->id;

        if ($this->shiftInterface->getExistingShiftStatus(captainId: $captainId)) {
            $this->shiftInterface->updateCaptainShiftStatus(
                captainId: $captainId,
                data: [
                    'shift_end' => now(),
                    'end_kilometer' => null,
                ],
            );
        }

        $shiftCreateData = [
            'shift_start' => now(),
            'start_kilometer' => null,
            'captain_id' => $captainId,
            'vehicle_id' => $vehicle_id,
        ];


        return $this->shiftInterface->createShiftStatus($shiftCreateData);
    }

    public function updateShiftStatus(int $captainId, $end_kilometer)
    {
        $captain = $this->shiftInterface->getCaptainWithCurrentOrder(captain_id: $captainId);


        if ($captain->currentOrder->isNotEmpty()) {
            throw ValidationException::withMessages([
                'shift' => [__('app/shift.end_failed_has_orders')],
            ]);
        }

        $updateData = [
            'shift_end' => now(),
            'end_kilometer' => $end_kilometer,
        ];

        try {
            $vehicle = $this->shiftInterface->getCaptainAssignedVehicle(assignee: $captainId);
            $updateData['vehicle_id'] = $vehicle->id;
        } catch (ModelNotFoundException $e) {
            // Vehicle not found, proceed without updating vehicle_id
        }

        return $this->shiftInterface->updateCurrentShift(captainId: $captainId, data: $updateData);
    }
}
