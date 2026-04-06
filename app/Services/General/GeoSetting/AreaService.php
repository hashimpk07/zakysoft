<?php
namespace App\Services\General\GeoSetting;

use App\Interfaces\General\GeoSettingInterface;
use Illuminate\Support\Facades\Auth;

class AreaService
{
    public function __construct(private readonly GeoSettingInterface $geoSettingInterface)
    {
    }

    public function getAreaList(array $filters = [],$perPage)
    {
        return $this->geoSettingInterface->getAreaList($filters,$perPage);
    }
    
    public function createArea(array $requestData)
    {
        $data['name']        = $requestData['name'];
        $data['quadrant_id'] = $requestData['region'];
        $data['created_by']  = Auth::id();
        return $this->geoSettingInterface->createArea($data);
    }

    public function getArea(int $id)
    {
        return $this->geoSettingInterface->getArea($id);
    }

    public function updateArea(int $id, array $requestData)
    {
        $data = [
            'name'        => $requestData['name'],
            'quadrant_id' => $requestData['region'],
        ];
        return $this->geoSettingInterface->updateArea($id, $data);
    }

    public function getAreaDetails(int $id)
    {
        return $this->geoSettingInterface->getAreaDetails($id);
    }
}