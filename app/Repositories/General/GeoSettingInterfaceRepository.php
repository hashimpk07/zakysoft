<?php

namespace App\Repositories\General;
use App\Interfaces\General\GeoSettingInterface;


use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\ZoneImport;
use App\Exports\ZoneImportErrorExport;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use App\Region;
use App\Quadrant;
use App\Zone;

class GeoSettingInterfaceRepository implements GeoSettingInterface
{
   
    public function getZoneList(array $filters, int $perPage)
    {
        $query = Zone::query()
            ->with(['mainZone', 'region.quadrant', 'tire', 'city',]);

        // Country filter
        if (!empty($filters['country'])) {
            $query->whereHas('mainZone', function ($q) use ($filters) {
                $q->where('id', $filters['country']);
            });
        }

        // Region filter
        if (!empty($filters['region'])) {
            $query->whereHas('region.quadrant', function ($q) use ($filters) {
                $q->where('id', $filters['region']);
            });
        }

        // Area filter
        if (!empty($filters['area'])) {
            $query->where('region_id', $filters['area']);
        }

        // Search filter
        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        return $query->paginate($perPage);
    }

    public function createZone(array $data)
    {
        return Zone::create($data);
    }

    public function getZone($id)
    {
        return Zone::select('id','main_zone_id','zoneId','name','region_id','area_name', 'polygon', 'status', 
                    'active_inactive', 'quadrant','tire_id','city_id')->where('id', $id)->firstOrFail();
    }

    public function updateZone(int $id, array $data)
    {
        $zone = Zone::findOrFail($id);
        $zone->update($data);
        return $zone->fresh();
    }

    public function statusChangeZone(int $id)
    {
        $zone = Zone::findOrFail($id);
        $zone->status = $zone->status === 'Y' ? 'N' : 'Y';
        $zone->save();
        return $zone->fresh();
    }

    public function importZoneFile(UploadedFile $file): array
    {
        $import = new ZoneImport();
        $import->import($file);
        $failures = $import->failures(); 
        $errorFilePath = null;
        if ($failures->isNotEmpty()) {
            $errors = $failures->map(function ($failed) {
                return array_merge(
                    $failed->values(),
                    ['errors' => implode(', ', $failed->errors())]
                );
            })->toArray();

            $fileName = 'zone-import-errors-' . now()->format('Ymd_His') . '-' . Str::random(6) . '.xlsx';
            $relativePath = "public/zone-import-errors/{$fileName}";

            Excel::store(new ZoneImportErrorExport(collect($errors)), $relativePath);

            $errorFilePath = Storage::url("zone-import-errors/{$fileName}");

            return [
                'success' => true
            ];
        }

        return [
            'success' => false
        ];
    }

    public function getRegionList(array $filters = [],$perPage)
    {
        $query = Quadrant::query()
            ->select('id', 'name') 
            ->orderBy('name');

        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        return $query->latest()->paginate($perPage);

    }
    public function createRegion(array $data)
    {
        return Quadrant::create($data);
    }

    public function getRegion($id = null)
    {
        if ($id) {
            return Quadrant::findOrFail($id);
        }  
        return Quadrant::all();
    }

    public function updateRegion(int $id, array $data)
    {
        $quadrant = Quadrant::findOrFail($id);
        $quadrant->update($data);
        return $quadrant;
    }
    public function getRegionDetails(int $id)
    {
        return Quadrant::with(['regions' => function ($query) {
            $query->select('id', 'name', 'quadrant_id');
        }])
        ->select('id', 'name')
        ->findOrFail($id);
    }

    public function getAreaList(array $filters = [], $perPage)
    {
        return Region::query()
            ->with('quadrant')
            ->select('id', 'name', 'quadrant_id') 
            ->when(!empty($filters['name']), function ($q) use ($filters) {
                $q->where('name', 'like', '%' . $filters['name'] . '%');
            })
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function createArea(array $data)
    {
        return Region::create($data);
    }

    public function getArea(int $id)
    {
        return  Region::findOrFail($id);
    }

    public function updateArea(int $id, array $data)
    {
        $region = Region::findOrFail($id);
        $region->update($data);

        return $region;
    }
    public function getAreaDetails(int $id)
    {
        return Region::with(['quadrant', 'zones'])->findOrFail($id);
    }
}