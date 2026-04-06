<?php
namespace App\Services\General\General;

use App\Interfaces\General\AssetInterface;


class AssetService
{
    public function __construct(private readonly AssetInterface $assetInterface)
    {
    }

    public function createAssetCategory($data)
    {
        $name = $data['name'];
        $userId = (int) auth('api')->id() ;
        return $this->assetInterface->createAssetCategory($name, $userId);
    }

    public function getAssetCategoryList()
    {
        return $this->assetInterface->getAssetCategoryList();
    }

    public function createAsset(array $data)
    {
        $data['created_by'] = (int) auth('api')->id();
        return $this->assetInterface->createAsset($data);
    }

    public function getAssetWithOutAssignCaptainList() 
    {
        return $this->assetInterface->getAssetWithOutAssignCaptainList();
    }

    public function assetAssignCaptain(array $data)
    {
        return $this->assetInterface->assetAssignCaptain($data);
    }

    public function getAssets(int $perPage) 
    {
        return $this->assetInterface->getAssets($perPage);
    }

}