<?php

namespace App\Services\General\SalesManagement;

use App\Designation;
use App\DesignationType;
use App\Interfaces\General\DesignationInterface;
use Illuminate\Http\Request;

final class DesignationService{
    public function __construct(protected readonly DesignationInterface $repo)
    {}

    public function listDesignations(Request $request)
    {
        $data = $this->repo->getDesignationType($request);


        $data->getCollection()->transform(fn($item) => [
            'id' => $item->id,
            'main_role' => $item->type_name,
            'sub_role' => $item->designation_name
        ]);

        return $data;
    }

    public function storeDesignation(array $data): Designation 
    {
        return $this->repo->create([
            'designation_type_id' => $data['main_role'],
            'name' => $data['name'],
            'name_ar' => $data['name_ar']
        ]);
    }

    public function updateDesignation(Designation $designation, array $data): Designation 
    {
        return $this->repo->update($designation, [
            'designation_type_id' => $data['main_role'],
            'name' => $data['name'],
            'name_ar' => $data['name_ar']
        ]);
    }

    public function storeType(array $data): DesignationType 
    {
        return $this->repo->createDesignationType($data);
    }
}