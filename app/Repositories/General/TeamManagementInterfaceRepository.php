<?php

namespace App\Repositories\General;

use App\Interfaces\General\TeamManagementInterface;
use App\SalesManager;
use App\User;

final class TeamManagementInterfaceRepository implements TeamManagementInterface
{
    public function getPaginatedManagers(array $filters, int $perPage = 10)
    {
        return SalesManager::with(['designation:id,name', 'region:id,name', 'user:id,name,email,status'])
            ->has('user')
            // Using when() for conditional filtering
            ->when($filters['q'] ?? null, function ($query, $term) {
                $query->whereHas('user', function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->when($filters['region'] ?? null, function ($query, $region) {
                $query->where('working_region_id', $region);
            })
            ->when($filters['role'] ?? null, function ($query, $role) {
                $query->where('designation_id', $role);
            })
            ->when($filters['status'] ?? null, function ($query, $status) {
                $query->whereHas('user', function ($q) use ($status) {
                    $q->where('status', $status);
                });
            })
            ->paginate($perPage);
    }

    public function findUser(int $id): ?User {
        return User::find($id);
    }

    public function updateOrCreateUser(array $data, ?User $user = null): User {
        if ($user) {
            $user->update(array_filter($data)); // array_filter prevents overwriting password with null
            return $user;
        }
        return User::create($data);
    }

    public function create(array $data): SalesManager {
        return SalesManager::create($data);
    }

    public function update(SalesManager $manager, array $data): SalesManager {
        $manager->update($data);
        return $manager;
    }

    public function handleDocuments(SalesManager $manager, array $newDocs, bool $clearOthers = false): void {
        if ($clearOthers) {
            $manager->documents()->where('name', '<>', 'Iqama Copy')->delete();
        }
        
        if (!empty($newDocs)) {
            $manager->documents()->createMany($newDocs);
        }
    }
}