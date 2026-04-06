<?php

namespace App\Interfaces;

use App\EmployeeCallLog;
use App\EmployeeCallParticipent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface OperationCallInterface
{
    public function getOperationsWithPresence(int $perPage, ?string $status = null): LengthAwarePaginator|Collection;

    public function saveCallLog(array $data, $user): bool|EmployeeCallLog;

    public function updateParticipent(EmployeeCallParticipent $participent, array $data): bool;

    public function notifyUsers($message, $user, $status);

    public function updateCallLog(EmployeeCallLog $call, array $data): bool;

    public function handleParticipantLeft(EmployeeCallLog $call, string $email);

    public function handleRoomDestroyed(EmployeeCallLog $call);
}
