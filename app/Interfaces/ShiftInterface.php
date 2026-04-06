<?php

namespace App\Interfaces;

use App\ActivityCheck;
use App\PresenceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

interface ShiftInterface
{
    public function startShift();

    public function endShift($shiftId, $userId): array;

    public function startBreak($shiftId): array;

    public function endBreak($breakId, $userId): array;

    public function getPresenceStatus(): string;

    public function setPresenceStatus($userId, $data): Model|PresenceStatus;

    public function getPresence($userId): Model|PresenceStatus|null;

    public function activityCheck($answer, Model|ActivityCheck $activityCheck, $userId): array;

    public function getDailyReports($date, $employee = null, $pagination, $currentPage): LengthAwarePaginator;

    public function getTimesheetReports($startDate, $endDate, $employee, $pagination): LengthAwarePaginator;
}
