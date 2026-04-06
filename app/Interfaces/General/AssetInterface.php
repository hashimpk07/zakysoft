<?php

namespace App\Interfaces\General;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

interface AssetInterface
{
    public function createAssetCategory(string $clientId,int $userId);
    public function getAssetCategoryList();
    public function createAsset(array $data);
    public function getAssetWithOutAssignCaptainList();
    public function assetAssignCaptain(array $data);
    public function getAssets(int $perPage);
}
