<?php
namespace App\Services\General\GeoSetting;

use App\Interfaces\General\GeoSettingInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\UploadedFile;

class RegionService
{
    public function __construct(private readonly GeoSettingInterface $geoSettingInterface)
    {
    }

    public function getRegionList(array $filters = [],$perPage)
    {
        return $this->geoSettingInterface->getRegionList($filters,$perPage);
    }
    
    public function createRegion(array $requestData)
    {
        $data['name']       = $requestData['name'];
        $data['created_by'] = Auth::id();
        return $this->geoSettingInterface->createRegion($data);
    }

    public function getRegion($id)
    {
        return $this->geoSettingInterface->getRegion($id);
           
    }

    public function updateRegion(int $id, array $requestData)
    {
        $data['name']       = $requestData['name'];
        return $this->geoSettingInterface->updateRegion($id, $data);
    }

    public function getRegionDetails(int $id)
    {
        return $this->geoSettingInterface->getRegionDetails($id);
    }
}