<?php

namespace App\Services\ThirdPartyLogistic;

use App\Interfaces\ThirdPartyLogisticInterface;
use App\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class VehicleService
{
    public function __construct(private readonly ThirdPartyLogisticInterface $vehicleRepository) {}

    public function createVehicle($request)
    {
        $companyId = $request->get('company_id_3pl');
        $employeeId = auth()->user()->id;

        $vehicleData = [
            'vehicle' => [
                'code'                   => $request->code,
                'region_id'              => $request->region_id,
                'zone_id'                => $request->zone_id,
                'brand'                  => $request->brand,
                'name'                   => $request->name,
                'number'                 => $request->number,
                'status'                 => $request->status,
                'type'                   => $request->type,
                'owner_name'             => $request->owner_name,
                'owner_email'            => $request->owner_email,
                'owner_number'           => $request->owner_number,
                'assigned_to'            => $request->assigned_to,
                'rc_file_path'           => $this->upload($request, 'rc_book'),
                'insurance_file_path'    => $this->upload($request, 'insurance'),
                'rc_book_expiry_date'    => $request->rc_book_expiry_date,
                'insurance_expiry_date'  => $request->insurance_expiry_date,
                'current_km'             => $request->current_km,
                'created_by'             => $employeeId,
                'created_at'             => now(),
            ],
            'images' => $this->uploadMultiple($request),
        ];

        $lastInsertId = $this->vehicleRepository->createVehicle($vehicleData, $companyId,$employeeId);

        if (is_int($lastInsertId)) {
            $vehicle = $this->getVehicle($lastInsertId);
            //$vehicle = Vehicle::find($lastInsertId);
            return [
                "success" => true,
                "vehicle" => $vehicle
            ];
        }
        return [
            "success" => false,
            "vehicle" => []
        ];
    }

    private function upload($request, $field)
    {
        if (!$request->hasFile($field)) return null;

        $file = $request->file($field);
        $name = uniqid().'_'.$file->getClientOriginalName();

        $file->storeAs('public/vehicle', $name);

        return $name;
    }

    private function uploadMultiple($request)
    {
        $files = [];

        if ($request->hasFile('vehicle_img')) {
            foreach ($request->file('vehicle_img') as $file) {
                $name = uniqid().'_'.$file->getClientOriginalName();
                $file->storeAs('public/vehicle/images', $name);
                $files[] = $name;
            }
        }

        return $files;
    }
    public function changeVehicleIdStatusFor3PL(int $vehicleId)
    {
        $this->vehicleRepository->changeVehicleIdStatus($vehicleId);
        return $this->getVehicle($vehicleId);
    }
    public function getVehicleDetailsFor3PL(int $vehicleId)
    {
        return $this->getVehicle($vehicleId);
    }
    private function getVehicle($vehicleId)
    {
        return $this->vehicleRepository->getVehicle($vehicleId);
    }
    public function updateVehicleFor3PL($request, int $vehicleId)
    {
        $vehicle = Vehicle::findOrFail($vehicleId);

        $vehicle->code                  = $request->code ?? $vehicle->code;
        $vehicle->region_id             = $request->region_id ?? $vehicle->region_id;
        $vehicle->zone_id               = $request->zone_id ?? $vehicle->zone_id;
        $vehicle->brand                 = $request->brand ?? $vehicle->brand;
        $vehicle->name                  = $request->name ?? $vehicle->name;
        $vehicle->number                = $request->number ?? $vehicle->number;
        $vehicle->status                = $request->status ?? $vehicle->status;
        $vehicle->type                  = $request->type ?? $vehicle->type;
        $vehicle->owner_name            = $request->owner_name ?? $vehicle->owner_name;
        $vehicle->owner_email           = $request->owner_email ?? $vehicle->owner_email;
        $vehicle->owner_number          = $request->owner_number ?? $vehicle->owner_number;
        $vehicle->assigned_to           = $request->assigned_to ?? $vehicle->assigned_to;
        if ($request->hasFile('rc_book')) {
            $vehicle->rc_file_path      = $this->upload($request, 'rc_book');
        }
        if ($request->hasFile('insurance')) {
            $vehicle->insurance_file_path = $this->upload($request, 'insurance');
        }
        $vehicle->rc_book_expiry_date   = $request->rc_book_expiry_date ?? $vehicle->rc_book_expiry_date;
        $vehicle->insurance_expiry_date  = $request->insurance_expiry_date ?? $vehicle->insurance_expiry_date;
        $vehicle->current_km            = $request->current_km ?? $vehicle->current_km;
        $vehicle->updated_at            = now();

        $vehicle->save();

        return $this->getVehicle($vehicleId);
    }


    public function updateVehicle($request, $vehicleId)
    {
        $userId = auth()->user()->id;
        $vehicle = $this->getVehicle($vehicleId);
        if (!$vehicle) {
            return ["error" => "Vehicle not found"];
        }

        $vehicleInput = $this->prepareInputData($request, $vehicle,$userId);
        $updated =  $this->vehicleRepository->updateVehicle($vehicleInput, $vehicleId, $userId);

        if (!$updated) {
            return ["error" => "Vehicle update failed"];
        }
        $this->vehicleRepository->updateExpireData("R C Book expire",$vehicleInput['rc_book_expiry_date'],
                $request->number,$vehicleId);

        $this->vehicleRepository->updateExpireData("Insurance expire",$vehicleInput['insurance_expiry_date'],
            $request->number,$vehicleId);
        return  $this->getVehicle($vehicleId);;
    }


    private function prepareInputData($request, $vehicle,$userId)
    {
        return [
            "code"                   => $request->code,
            "region_id"              => $request->region_id,
            "brand"                  => $request->brand,
            "name"                   => $request->name,
            "number"                 => $request->number,
            "status"                 => $request->status,
            "type"                   => (int) $request->type,
            "owner_name"             => $request->owner_name,
            "owner_email"            => $request->owner_email,
            "owner_number"           => $request->owner_number,
            "rc_file_path"           => $request->hasFile('rc_book')
                                        ? $this->uploadFile($request->file('rc_book'), 'public/vehicle', $vehicle->rc_file_path)
                                        : $vehicle->rc_file_path,

            "rc_book_expiry_date"    => Carbon::parse($request->rc_book_expiry_date)->format('Y-m-d'),
            "insurance_expiry_date"  => Carbon::parse($request->insurance_expiry_date)->format('Y-m-d'),

            "insurance_file_path"    => $request->hasFile('insurance')
                                        ? $this->uploadFile($request->file('insurance'), 'public/vehicle', $vehicle->insurance_file_path)
                                        : $vehicle->insurance_file_path,

            "current_km"             => $request->current_km,
            "updated_by"             => $userId,
            "updated_at"             => now()
        ];
    }

    public function uploadFile($file, $storagePath, $previous = null)
    {
        if(($file instanceof UploadedFile)) {
            $file = $file->storePublicly($storagePath, ['disk' => $this->disk()]);
        } else {
            $file = Storage::disk($this->disk())->put($storagePath, $file, 'public');
            if($file) {
                $file = $storagePath;
            }
        }
        
        if ($previous) {
            Storage::disk($this->disk())->delete($previous);
        }

        return $file;
    }
    protected function disk()
    {
        return config('filesystems.default');
    }
    public function assignVehicleToCaptainFor3PL($request)
    {
        $userId = auth()->user()->id;
        $captainId = $request->captain_id;
        $vehicleId = $request->vehicle_id;
        return  $this->vehicleRepository->assignVehicleToCaptain($vehicleId,$captainId, $userId);

    }
    public function searchOwnerFor3PL(int $ownerId)
    {
        return $this->vehicleRepository->getOwnerData($ownerId);
    }
    public function nextVehicleCode()
    {
        $lastId = Vehicle::max('id'); 
        $nextInsertId = $lastId ? $lastId + 1 : 1;
        return 'VH-' . $nextInsertId;
    }
}