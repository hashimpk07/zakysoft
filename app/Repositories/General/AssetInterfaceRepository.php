<?php

namespace App\Repositories\General;
use App\Interfaces\General\AssetInterface;

use App\asset;
use App\AssetCategory;
use Illuminate\Support\Collection;

class AssetInterfaceRepository implements AssetInterface
{
    public function createAssetCategory($name,$userId)
    {
       return AssetCategory::create([
            'name'       => $name,
            'created_by' => auth('api')->id(),
        ]);
    }
    public function getAssetCategoryList() 
    {
        return  AssetCategory::select('id', 'name')->get();
    }

    public function createAsset(array $data)
    {
        return Asset::create($data);
    }

    public function getAssetWithOutAssignCaptainList()
    {
        return Asset::select('id', 'asset_name as name', 'reference_number as ref_no')
                ->whereNull('captain_id')   
                ->get();
    }

    public function assetAssignCaptain(array $data)
    {
        $asset = Asset::findOrFail($data['asset_id']);

        $asset->update([
            'captain_id' => $data['captain_id'],
        ]);
        return $asset;
    }

    public function getAssets(int $perPage = 10)
    {
        return Asset::select(
            'id',
            'category_id',
            'asset_name',
            'reference_number',
            'captain_id',
            'created_at',
            'created_by'
        )
        ->with(['category:id,name', 'captain:id,firstname']) 
        ->latest()
        ->paginate($perPage);
    }

}