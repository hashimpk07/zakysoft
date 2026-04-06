<?php

namespace App\Repositories\General;

use App\Captain;
use App\Files_and_remainders;
use App\Interfaces\General\VehicleInterface;
use App\Vehicle;
use App\VehicleCaptain;
use App\VehicleImage;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

final class VehicleInterfaceRepository implements VehicleInterface
{
    public function statistics(): array
    {
        return [
            'all' => Vehicle::count(),
            'no_of_assigned' => Vehicle::whereNotNull('assigned_to')->count(),
            'no_of_free' => Vehicle::whereNull('assigned_to')->count(),
            'owned_vehicle' => Vehicle::where('owner_name', '2')->count(),
            'other_vehicle' => Vehicle::where('owner_name', '!=', '2')->count(),
            'rented_vehicle' => Vehicle::whereHas('captain', fn($q) => $q->where('captain_employment_type_id', 2))->count(),
            'with_sponsor' => Vehicle::whereHas('captain', fn($q) => $q->where('captain_employment_type_id', 1))->count(),
        ];
    }

    public function filter(Request $request, int $perPage = 10): LengthAwarePaginator
    {
        return Vehicle::query()
            ->with('region:id,name', 'vehicleType:id,name', 'captain:id,firstname,job_type', 'partner:id,first_name,last_name')
            ->when($request->region, fn($q, $region) => $q->where('region_id', $region))
            ->when($request->vehicle_type, fn($q, $type) => $q->where('type', $type))
            ->when($request->captain, fn($q, $captain) => $q->whereHas('captain', fn($q) => $q->whereLike(['code', 'user.name', 'user.email'], $captain)))
            ->when(
                $request->status,
                fn($q, $status) => match ($status) {
                    'Free' => $q->whereNull('assigned_to'),
                    'Assigned' => $q->whereNotNull('assigned_to'),
                    default => $q,
                },
            )
            ->when($request->plate_no, fn($q, $plate_no) => $q->whereLike('number', $plate_no))
            ->when($request->employment_type, fn($q, $employment_type) => $q->whereHas('captain', fn($q) => $q->where('captain_employment_type_id', $employment_type)))
            ->when($request->owner, fn($q, $owner) => $q->where('owner_name', $owner))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getImages(int $vehicleId)
    {
        return VehicleImage::where('vehicle_id', $vehicleId)->get();
    }

    public function isCaptainAssigned(int $captainId): bool
    {
        return Vehicle::where('assigned_to', $captainId)->exists();
    }

    public function findAssignedToCaptain(int $captainId): ?Vehicle
    {
        return Vehicle::where('assigned_to', $captainId)->first();
    }

    public function deactivateCurrentAssignment(int $vehicleId): void
    {
        VehicleCaptain::where('vehicle_id', $vehicleId)
            ->where('status', 'Active')
            ->orderByDesc('id')
            ->first()
            ?->update([
                'status' => 'Inactive',
                'to_date' => now(),
                'detached_by' => Auth::id(),
            ]);
    }

    public function assignToCaptain(int $vehicleId, int $captainId, Captain $captain): VehicleCaptain
    {
        Vehicle::find($vehicleId)?->update(['assigned_to' => $captainId]);

        return VehicleCaptain::create([
            'vehicle_id' => $vehicleId,
            'captain_id' => $captainId,
            'created_by' => Auth::id(),
            'from_date' => now(),
            'is_rented' => $captain->rental(),
            'rented_valid_at' => $captain->rental() ? $captain->rent_valid_from : null,
            'rent' => $captain->rental() ? $captain->daily_rent : null,
        ]);
    }

    public function detachCaptain(Vehicle $vehicle): void
    {
        $vehicle->update(['assigned_to' => null]);
    }

     public function create(array $data): Vehicle
    {
        return Vehicle::create($data);
    }
 
    public function update(Vehicle $vehicle, array $data): Vehicle
    {
        $vehicle->update($data);
 
        return $vehicle->fresh();
    }
 
    public function createCaptainAssignment(array $data): VehicleCaptain
    {
        return VehicleCaptain::create($data);
    }
 
    public function syncExpiryReminder(string $detail, string $date, string $name, int $vehicleId): void
    {
        $data = [
            'name'           => $name,
            'date'           => $date,
            'type'           => 'Vehicle',
            'detail'         => $detail,
            'reference_path' => "/vehicles/{$vehicleId}/edit",
            'reference_id'   => $vehicleId,
        ];
 
        Files_and_remainders::updateOrCreate(
            ['type' => 'Vehicle', 'reference_id' => $vehicleId, 'detail' => $detail],
            $data
        );
    }
}
