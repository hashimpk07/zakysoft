<?php

namespace App\Interfaces\General;

use App\Http\Requests\General\Employees\StoreEmployeeRequest;
use App\Http\Requests\General\Employees\UpdateEmployeeRequest;
use App\User;

interface EmployeeInterface
{
    public function getPaginated(array $filters, int $perPage = 10);
    public function getSelectableRoles(array $excludeIds = [3]);
    public function getSelectableClients();
    public function get3plCompanies();

    public function createEmployee(StoreEmployeeRequest $request): User;
 
    public function updateEmployee(UpdateEmployeeRequest $request, User $admin): User;
    public function syncPermissionZonesBranches(int $userId, array $payload): void;

}