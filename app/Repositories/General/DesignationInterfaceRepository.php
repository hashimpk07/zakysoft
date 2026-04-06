<?php

namespace App\Repositories\General;

use App\Designation;
use App\DesignationType;
use App\Interfaces\General\DesignationInterface;
use Illuminate\Http\Request;

final class DesignationInterfaceRepository implements DesignationInterface
{
    public function getDesignationType(Request $request)
    {
        return DesignationType::query()
            ->leftJoin('designations', 'designations.designation_type_id', 'designation_types.id')
            ->select([
                'designations.id',
                'designations.name as designation_name',
                'designations.name_ar as designation_name_ar',
                'designation_types.id as type_id',
                'designation_types.name as type_name'
            ])
            ->when($filters['main_role'] ?? null, fn($q, $role) => $q->where('designation_types.id', $role))
            ->when($filters['sub_role'] ?? null, fn($q, $role) => $q->where('designations.id', $role))
            ->paginate($request->get('per_page', 10));
    }

    public function create(array $data): Designation {
        return Designation::create($data);
    }

    public function update(Designation $designation, array $data): Designation {
        $designation->update($data);
        return $designation;
    }

    public function createDesignationType(array $data): DesignationType 
    {
        return DesignationType::create($data);
    }

}