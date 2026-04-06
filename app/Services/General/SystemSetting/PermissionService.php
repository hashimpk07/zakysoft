<?php
namespace App\Services\General\SystemSetting;

use App\Interfaces\General\SystemSettingInterface;
use Illuminate\Support\Facades\Auth;



class PermissionService
{
    public function __construct(private readonly SystemSettingInterface $systemSettingInterface)
    {
    }

    public function getRoleList()
    {
        return $this->systemSettingInterface->getRoleList();
    }

    public function createRole(string $roleName)
    {
        return $this->systemSettingInterface->createRole(['name' => $roleName,'display_name' => $roleName,'guard_name' => "web" ]);
    }

    public function getPermissionsByRole(int $roleId)
    {
        $data =  $this->systemSettingInterface->getRoleWithPermissions($roleId);
        $role  = $data['role'];
        $data['role_permission_id'] =  $role->permissions->pluck('id')->toArray();
        return $data;
    }

    public function updatePermissionsByRole(int $roleId, array $permissions)
    {
        return $this->systemSettingInterface->updatePermissions($roleId, $permissions);
    }

    public function removedRole(int $roleId): bool
    {
        return $this->systemSettingInterface->deleteRole($roleId);
    }

}