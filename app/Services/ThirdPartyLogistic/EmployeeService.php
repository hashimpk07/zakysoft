<?php

namespace App\Services\ThirdPartyLogistic;

use App\Interfaces\ThirdPartyLogisticInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class EmployeeService
{
    public function __construct(private readonly ThirdPartyLogisticInterface $repo) {}
   
    public function getEmployeeListFor3PL($request)
    {
        $companyId = $request->get('company_id_3pl');
        $perPage   = $request->get('per_page', 20);
        $filters = $request->only(['search']);
        return $this->repo->getEmployees($companyId, $filters, $perPage);
    }
    public function changeEmployeeStatusFor3PL($employeeId)
    {
        return $this->repo->changeEmployeeStatus($employeeId);
    }
    
   
}