<?php
namespace App\Services\General\GeoSetting;

use App\Interfaces\General\GeoSettingInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\UploadedFile;

class ZoneService
{
    public function __construct(private readonly GeoSettingInterface $geoSettingInterface)
    {
    }

    public function getZoneList(array $filters, int $perPage)
    {
        return $this->geoSettingInterface->getZoneList($filters, $perPage);
    }

    public function createZone(array $validatedData)
    {
        $data = [
            'zoneId' => $validatedData['geo_fence'] ?? null,
            'name' => $validatedData['name'],
            'main_zone_id' => $validatedData['main_zone'],
            'quadrant' => $validatedData['quadrant'],
            'region_id' => $validatedData['region_id'],
            'tire_id' => $validatedData['tire_id'] ?? null,
            'city_id' => $validatedData['city_id'] ?? null,
            'created_by' => Auth::id(),
        ];

        return $this->geoSettingInterface->createZone($data);
    }
    public function getZoneDetails($id) 
    {
        return $this->geoSettingInterface->getZone($id);
    }

    public function updateZone(int $id, array $validatedData)
    {
        $data = [
            'zoneId'       => $validatedData['geo_fence'] ?? null,
            'name'         => $validatedData['name'],
            'main_zone_id' => $validatedData['main_zone'],
            'quadrant'     => $validatedData['quadrant'],
            'region_id'    => $validatedData['region_id'],
            'tire_id'      => $validatedData['tire_id'] ?? null,
            'city_id'      => $validatedData['city_id'] ?? null,
            'updated_by'   => Auth::id(),
        ];

        return $this->geoSettingInterface->updateZone($id, $data);
    }

    public function statusChangeZone(int $id)
    {
        return $this->geoSettingInterface->statusChangeZone($id);
    }

    public function importZoneData(UploadedFile $file): array
    {
        $import =  $this->geoSettingInterface->importZoneFile($file);
        if ( $import['success'] ){
            $data['status'] = "success";
            $data['message'] = "zone data imported successfully";
        }else{
            $data['status'] = "failed";
            $data['message'] = "zone data imported failed";
        }
        return $data;
    }

}