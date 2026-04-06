<?php

namespace App\Services\General\Vehicle;

use App\Captain;
use App\Http\Requests\General\Vehicle\StoreVehicleRequest;
use App\Http\Requests\General\Vehicle\UpdateVehicleRequest;
use App\Http\Requests\General\Vehicle\VehicleFilterRequest;
use App\Http\Resources\General\Vehicle\VehicleResource;
use App\Interfaces\General\VehicleInterface;
use App\Traits\HasFileUpload;
use App\Vehicle;
use App\VehicleCaptain;
use App\VehicleImage;
use Carbon\Carbon;
use Facades\App\Services\OrderStatusLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class VehicleService
{
    use HasFileUpload;
    public function __construct(protected readonly VehicleInterface $vehicleInterface) {}

    public function statistics(): array
    {
        return $this->vehicleInterface->statistics();
    }

    public function list(VehicleFilterRequest $request)
    {
        $data = VehicleResource::collection($this->vehicleInterface->filter($request))
            ->response()
            ->getData(true);
        return [
            'vehicles' => $data['data'],
            'pagination' => $data['meta'],
        ];
    }
    public function images(int $vehicleId)
    {
        return $this->vehicleInterface->getImages($vehicleId)->map(
            fn($img) => [
                'id' => $img->id,
                'image' => asset('storage/vehicle/images/' . $img->name),
            ],
        );
    }

    public function isCaptainAssigned(int $captainId): bool
    {
        return $this->vehicleInterface->isCaptainAssigned($captainId);
    }

     public function store(StoreVehicleRequest $request): Vehicle
    {
        return DB::transaction(function () use ($request) {
            if ($request->assigned_to) {
                $previousVehicle = $this->vehicleInterface->findAssignedToCaptain((int) $request->assigned_to);
 
                if ($previousVehicle) {
                    $this->vehicleInterface->detachCaptain($previousVehicle);
                }
            }
 
            $vehicle = $this->vehicleInterface->create($this->buildVehiclePayload($request));
 
            if ($request->assigned_to) {
                $captain = Captain::findOrFail((int) $request->assigned_to);
 
                $this->vehicleInterface->createCaptainAssignment(
                    $this->buildCaptainAssignmentPayload($vehicle->id, $captain)
                );
            }
 
            $this->syncExpiryReminders($vehicle, $request->rc_book_expiry_date, $request->insurance_expiry_date, $request->number);
            $this->uploadVehicleImages($vehicle->id, $request->file('vehicle_img', []));
            $this->logVehicleCreation($vehicle);
 
            return $vehicle;
        });
    }
 
    public function update(Vehicle $vehicle, UpdateVehicleRequest $request): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $request) {
            $updated = $this->vehicleInterface->update(
                $vehicle,
                $this->buildUpdatePayload($request, $vehicle)
            );
 
            $this->syncExpiryReminders(
                $updated,
                $request->rc_book_expiry_date,
                $request->insurance_expiry_date,
                $request->number
            );
 
            $this->logVehicleUpdate($updated);
 
            return $updated;
        });
    }
 
    public function assignVehicleToCaptain(int $vehicleId, int $captainId, array $images = []): VehicleCaptain
    {
        return DB::transaction(function () use ($vehicleId, $captainId, $images) {
            $captain         = Captain::with('user')->findOrFail($captainId);
            $previousVehicle = $this->vehicleInterface->findAssignedToCaptain($captainId);
 
            if ($previousVehicle) {
                $this->vehicleInterface->detachCaptain($previousVehicle);
            }
 
            $this->vehicleInterface->deactivateCurrentAssignment($vehicleId);
 
            $assignment = $this->vehicleInterface->assignToCaptain($vehicleId, $captainId, $captain);
 
            foreach ($images as $image) {
                $assignment->images()->create([
                    'path' => $this->uploadFile($image, 'public/vehicle_image'),
                ]);
            }
 
            $this->logAssignment($assignment->vehicle->number, $captain, $previousVehicle !== null);
 
            return $assignment;
        });
    }
 
    // -------------------------------------------------------------------------
    // Payload builders
    // -------------------------------------------------------------------------
 
    private function buildVehiclePayload(StoreVehicleRequest $request): array
    {
        return [
            'code'                  => $request->code,
            'region_id'             => $request->region_id,
            'brand'                 => $request->brand,
            'name'                  => $request->name,
            'number'                => $request->number,
            'status'                => $request->status,
            'type'                  => $request->type,
            'owner_name'            => $request->owner_name,
            'owner_email'           => $request->owner_email,
            'owner_number'          => $request->owner_number,
            'assigned_to'           => $request->assigned_to ?: null,
            'current_km'            => $request->current_km,
            'rc_file_path'          => $request->hasFile('rc_book')
                                        ? $this->uploadFile($request->file('rc_book'), 'public/vehicle')
                                        : null,
            'rc_book_expiry_date'   => Carbon::createFromFormat('m/d/Y', $request->rc_book_expiry_date),
            'insurance_expiry_date' => Carbon::createFromFormat('m/d/Y', $request->insurance_expiry_date),
            'insurance_file_path'   => $request->hasFile('insurance')
                                        ? $this->uploadFile($request->file('insurance'), 'public/vehicle')
                                        : null,
            'created_by'            => Auth::id(),
        ];
    }
 
    private function buildUpdatePayload(UpdateVehicleRequest $request, Vehicle $vehicle): array
    {
        return [
            'code'                  => $request->code,
            'region_id'             => $request->region_id,
            'brand'                 => $request->brand,
            'name'                  => $request->name,
            'number'                => $request->number,
            'status'                => $request->status,
            'type'                  => (int) $request->type,
            'owner_name'            => $request->owner_name,
            'owner_email'           => $request->owner_email,
            'owner_number'          => $request->owner_number,
            'current_km'            => $request->current_km,
            'rc_file_path'          => $request->hasFile('rc_book')
                                        ? $this->uploadFile($request->file('rc_book'), 'public/vehicle', $vehicle->rc_file_path)
                                        : $vehicle->rc_file_path,
            'rc_book_expiry_date'   => Carbon::createFromFormat('m/d/Y', $request->rc_book_expiry_date)->format('Y-m-d'),
            'insurance_expiry_date' => Carbon::createFromFormat('m/d/Y', $request->insurance_expiry_date)->format('Y-m-d'),
            'insurance_file_path'   => $request->hasFile('insurance')
                                        ? $this->uploadFile($request->file('insurance'), 'public/vehicle', $vehicle->insurance_file_path)
                                        : $vehicle->insurance_file_path,
            'updated_by'            => Auth::id(),
        ];
    }
 
    private function buildCaptainAssignmentPayload(int $vehicleId, Captain $captain): array
    {
        return [
            'vehicle_id'      => $vehicleId,
            'captain_id'      => $captain->id,
            'created_by'      => Auth::id(),
            'from_date'       => now(),
            'is_rented'       => $captain->rental(),
            'rented_valid_at' => $captain->rental() ? $captain->rent_valid_from : null,
            'rent'            => $captain->rental() ? $captain->daily_rent : null,
        ];
    }
 
    // -------------------------------------------------------------------------
    // Side effects
    // -------------------------------------------------------------------------
 
    private function syncExpiryReminders(Vehicle $vehicle, string $rcDate, string $insuranceDate, string $number): void
    {
        $this->vehicleInterface->syncExpiryReminder('RC Book expire',    $rcDate,        $number, $vehicle->id);
        $this->vehicleInterface->syncExpiryReminder('Insurance expire',  $insuranceDate, $number, $vehicle->id);
    }
 
    private function uploadVehicleImages(int $vehicleId, array $images): void
    {
        foreach ($images as $image) {
            $imgName = uniqid() . $image->getClientOriginalName();
            $image->storeAs('public/vehicle/images/', $imgName);
 
            VehicleImage::create([
                'vehicle_id' => $vehicleId,
                'name'       => $imgName,
                'created_by' => Auth::id(),
            ]);
        }
    }
 
    private function logVehicleCreation(Vehicle $vehicle): void
    {
        OrderStatusLog::logs('Vehicle Creation', "New Vehicle {$vehicle->number} Created", Auth::id());
    }
 
    private function logVehicleUpdate(Vehicle $vehicle): void
    {
        OrderStatusLog::logs('Vehicle Updated', "Vehicle {$vehicle->number} Updated", Auth::id());
    }
 
    private function logAssignment(string $vehicleNumber, Captain $captain, bool $wasReassigned): void
    {
        $action  = $wasReassigned ? 'reassigned' : 'assigned to';
        OrderStatusLog::logs('Vehicle Assigning', "Vehicle {$vehicleNumber} {$action} {$captain->firstname} {$captain->lastname}", Auth::id());
    }

    public function getNextVehicleId(): array
    {
        $vehicleId = 'VH-001';

        if (Vehicle::count() > 0) {
            $lastVehicle = Vehicle::orderBy('id', 'desc')->where('code', '<>', '')->first();
            $lastVehicleCode = explode('-', $lastVehicle->code);
            $clientCount = $lastVehicleCode[1] + 1;
            $numLength = strlen((string) $clientCount);

            if ($numLength == 1) {
                $vehicleId = 'VH-00' . $clientCount;
            }
            if ($numLength == 2) {
                $vehicleId = 'VH-0' . $clientCount;
            }
            if ($numLength == 3) {
                $vehicleId = 'VH-' . $clientCount;
            }
        }

        return ["code" => $vehicleId];
    }
}
