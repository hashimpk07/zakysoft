<?php

namespace App\Interfaces\General;

use App\SalesManager;
use App\User;

interface TeamManagementInterface{
    public function getPaginatedManagers(array $filters, int $perPage = 10);
    public function findUser(int $id): ?User;
    public function updateOrCreateUser(array $data, ?User $user = null): User;
    public function create(array $data): SalesManager;
    public function update(SalesManager $manager, array $data): SalesManager;
    public function handleDocuments(SalesManager $manager, array $newDocs, bool $clearOthers = false): void;
}