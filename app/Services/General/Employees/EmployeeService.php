<?php

namespace App\Services\General\Employees;

use App\EmployeeEditsLog;
use App\Http\Requests\General\Employees\StoreEmployeeRequest;
use App\Http\Requests\General\Employees\UpdateEmployeeRequest;
use App\Interfaces\General\EmployeeInterface;
use App\Role;
use App\User;
use Facades\App\Services\OrderStatusLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;


final class EmployeeService
{

    public function __construct(protected readonly EmployeeInterface $interface)
    {
    }
    public function getEmployees(Request $request)
    {
        return $this->interface->getPaginated($request->all(), $request->get('per_page', 10));
    }
    public function getFilters(): array
    {
        $employeeTypes = [
            [
                'id' => 1,
                'key' => User::TYPE_CLIENT_EMPLOYEE,
                'label' => 'Client Employee'
            ],
            [
                'id' => 2,
                'key' => User::TYPE_3PL_EMPLOYEE,
                'label' => '3PL Employee'
            ],
            [
                'id' => 3,
                'key' => User::TYPE_OWN_EMPLOYEE,
                'label' => 'Leajlak Employee'
            ]
        ];
        return [
            'clients' => $this->interface->getSelectableClients(),
            'roles' => $this->interface->getSelectableRoles(),
            'third_party_companies' => $this->interface->get3plCompanies(),
            'employee_types' => $employeeTypes
        ];
    }

   public function store(StoreEmployeeRequest $request): User
    {
        return $this->interface->createEmployee($request);
    }
 
    public function update(User $user, UpdateEmployeeRequest $request): User
    {
        $oldName = $user->name;
        $oldRole = $user->role_id;
 
        $updated = $this->interface->updateEmployee($request, $user);
 
        Cache::forget('user-permission-' . $user->id);
 
        $this->logChanges($request, $updated, $oldName, $oldRole);
        $this->logEmployeeEdit($updated, $request->reason);
 
        return $updated;
    }
 
    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------
 
    private function logChanges(
        UpdateEmployeeRequest $request,
        User $admin,
        string $oldName,
        mixed $oldRole
    ): void {
        $changes = [];
 
        if ($oldName !== $request->name) {
            $changes[] = "Name changed from '{$oldName}' to '{$request->name}'";
        }
 
        if ($oldRole != $request->role) {
            $roleBefore = Role::find($oldRole)?->name ?? 'Unknown';
            $roleAfter  = Role::find($request->role)?->name ?? 'Unknown';
            $changes[]  = "Role changed from '{$roleBefore}' to '{$roleAfter}'";
        }
 
        if ($request->filled('password')) {
            $changes[] = 'Password updated';
        }
 
        if ($request->filled('data_permission')) {
            $changes[] = 'Data permission settings updated';
        }
 
        if (empty($changes)) {
            return;
        }
 
        $content = "Admin ID {$admin->id} ({$admin->email}) updated: " . implode(', ', $changes);
 
        OrderStatusLog::logs('Admin Management', $content, Auth::id());
    }
 
    private function logEmployeeEdit(User $admin, ?string $reason): void
    {
        EmployeeEditsLog::create([
            'editor_id'      => auth()->id(),
            'target_user_id' => $admin->id,
            'reason'         => $reason,
        ]);
    }
}