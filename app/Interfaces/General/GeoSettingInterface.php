<?php

namespace App\Interfaces\General;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Calculation\Statistical\Distributions\F;

interface GeoSettingInterface
{
    public function getZoneList(array $filters, int $perPage);
    public function createZone(array $data);
    public function getZone(int $id);
    public function updateZone(int $id, array $data);
    public function statusChangeZone(int $id);
    public function importZoneFile(UploadedFile $file): array;
    public function getRegionList(array $data,$perPage);
    public function createRegion(array $data);
    public function getRegion(?int $id = null);
    public function updateRegion(int $id, array $data);
    public function getRegionDetails(int $id);
    public function getAreaList(array $data,$perPage);
    public function createArea(array $data);
    public function getArea(int $id);
    public function updateArea(int $id, array $data);
    public function getAreaDetails(int $id);


}