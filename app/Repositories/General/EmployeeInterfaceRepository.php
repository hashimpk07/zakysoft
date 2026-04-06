<?php
namespace App\Repositories\General;

use App\Client;
use App\Http\Requests\General\Employees\StoreEmployeeRequest;
use App\Http\Requests\General\Employees\UpdateEmployeeRequest;
use App\Interfaces\General\EmployeeInterface;
use App\Role;
use App\ThirdPartyLogisticCompany;
use App\ThirdPartyLogisticCompanyUser;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class EmployeeInterfaceRepository implements EmployeeInterface
{
    public function getPaginated(array $filters, int $perPage = 50)
    {
        return User::select('id', 'name', 'email', 'role_id', 'status')->with('role:id,name')
            ->when($filters['q'] ?? null, function ($query, $term) {
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'LIKE', "%{$term}%")
                        ->orWhere('email', 'LIKE', "%{$term}%");
                });
            })
            ->when($filters['role'] ?? null, function ($query, $roles) {
                $query->whereIn('role_id', (array) $roles);
            })
        // Business Logic: Exclude specific roles and ensure role exists
            ->whereNotIn('role_id', [3])
            ->whereNotNull('role_id')
            ->where('role_id', '!=', '')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getSelectableRoles(array $excludeIds = [3])
    {
        return Role::whereNotIn('id', $excludeIds)->select('id', 'name')->get();
    }

    public function getSelectableClients()
    {
        return Client::select('id', 'user_id')
            ->with(['user' => fn($q) => $q->select('id', 'name')])
            ->get()
            ->map(fn($q) => ['id' => $q->id, 'name' => $q->user->name]);
    }

    public function get3plCompanies()
    {
        return ThirdPartyLogisticCompany::select('id', 'name')->get();
    }

    public function createEmployee(StoreEmployeeRequest $request): User
    {
        $user = User::create([
            'name'            => $request->name,
            'email'           => $request->email,
            'password'        => Hash::make($request->password),
            'role_id'         => $request->role,
            'type'            => $request->employee_type,
            'status'          => $request->get('status', User::STATUS_ACTIVE),
            'data_permission' => $request->data_permission,
        ]);

        $this->syncEmployeeClients($user, $request->client);
        $this->syncRole($user, $request->role);
        $this->sync3plCompany($user->id, $request->get('3pl_company'));

        $this->syncPermissionZonesBranches($user->id, [
            'zones'           => $request->zone,
            'branches'        => $request->branch,
            'clients'         => $request->clients,
            'regions'         => $request->region,
            'data_permission' => $request->data_permission,
        ]);

        return $user;
    }

    public function updateEmployee(UpdateEmployeeRequest $request, User $admin): User
    {
        $admin->update([
            'type'   => $request->employee_type,
            'name'   => $request->name,
            'status' => $request->get('status', User::STATUS_ACTIVE),
        ]);

        if ($request->filled('client')) {
            $this->syncEmployeeClients($admin, $request->client);
        }

        if ($request->filled('data_permission')) {
            $admin->update(['data_permission' => $request->data_permission]);
        }

        if ($request->filled('password')) {
            $admin->update(['password' => Hash::make($request->password)]);
        }

        if ($request->filled('role')) {
            $this->syncRole($admin, $request->role);
        }

        $this->sync3plCompany($admin->id, $request->get('3pl_company'));

        $this->syncPermissionZonesBranches($admin->id, [
            'zones'           => $request->zone,
            'branches'        => $request->branch,
            'clients'         => $request->clients,
            'regions'         => $request->region,
            'data_permission' => $request->data_permission,
        ]);

        return $admin->fresh();
    }

    public function syncPermissionZonesBranches(int $userId, array $payload): void
    {
        DB::table('emp_permission_zones_branches')->where('user_id', $userId)->delete();

        $zones          = $payload['zones'] ?? [];
        $branches       = $payload['branches'] ?? [];
        $clients        = $payload['clients'] ?? [];
        $regions        = $payload['regions'] ?? [];
        $dataPermission = $payload['data_permission'] ?? null;
        $now            = now();
        $createdBy      = auth()->id();

        $rows = match (true) {
            $dataPermission === User::DATA_PERMISSION_ZONE_BASED && ! empty($zones)      =>
            array_map(fn($zone) => [
                'user_id'    => $userId,
                'zone_id'    => $zone,
                'created_by' => $createdBy,
                'created_at' => $now,
                'updated_at' => $now,
            ], $zones),

            $dataPermission === User::DATA_PERMISSION_BRANCH_BASED && ! empty($branches) =>
            array_map(fn($branch) => [
                'user_id'    => $userId,
                'branch_id'  => $branch,
                'created_by' => $createdBy,
                'created_at' => $now,
                'updated_at' => $now,
            ], $branches),

            $dataPermission === User::DATA_PERMISSION_CLIENT_BASED && ! empty($clients)  =>
            array_map(fn($client) => [
                'user_id'    => $userId,
                'client_id'  => $client,
                'created_by' => $createdBy,
                'created_at' => $now,
                'updated_at' => $now,
            ], $clients),

            $dataPermission === User::DATA_PERMISSION_REGION_BASED && ! empty($regions)  =>
            array_map(fn($region) => [
                'user_id'     => $userId,
                'quadrant_id' => $region,
                'created_by'  => $createdBy,
                'created_at'  => $now,
                'updated_at'  => $now,
            ], $regions),

            default                                                                     => [],
        };

        if (! empty($rows)) {
            DB::table('emp_permission_zones_branches')->insert($rows);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function syncEmployeeClients(User $user, mixed $clients): void
    {
        if ($clients) {
            $user->employeeClient()->sync((array) $clients);
        }
    }

    private function syncRole(User $user, mixed $role): void
    {
        $user->update(['role_id' => $role]);
        $user->syncRoles($role);
    }

    private function sync3plCompany(int $userId, mixed $companyId): void
    {
        if ($companyId) {
            ThirdPartyLogisticCompanyUser::updateOrCreate(
                ['user_id' => $userId],
                ['third_party_logistic_company_id' => $companyId]
            );
        } else {
            ThirdPartyLogisticCompanyUser::where('user_id', $userId)->delete();
        }
    }
}
