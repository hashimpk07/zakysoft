<?php

namespace App\Services;

use App\Interfaces\ShiftInterface;
use App\PresenceStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class ShiftService
{
    public function __construct(private readonly ShiftInterface $shiftInterface) {}
    public function startShift()
    {
        return $this->shiftInterface->startShift();
    }

    public function endShift($shiftId, $userId)
    {
        return $this->shiftInterface->endShift($shiftId, $userId);
    }

    public function startBreak($shiftId): array
    {
        return $this->shiftInterface->startBreak($shiftId);
    }

    public function endBreak($breakId, $userId): array
    {
        return $this->shiftInterface->endBreak($breakId, $userId);
    }

    public function getPresenceStatus(): string
    {
        return $this->shiftInterface->getPresenceStatus();
    }

    public function getPresence($userId): Model|PresenceStatus|null
    {
        return $this->shiftInterface->getPresence($userId);
    }

    public function logShiftAction($details)
    {
        Log::channel('shift_tracking')->info($details);
    }

    public function activityCheck($answer, Model $activityCheck, $userId): array
    {
        return $this->shiftInterface->activityCheck($answer, $activityCheck, $userId);
    }

    public function getDailyReports(Carbon $date, $employee = null, $pagination = 50, $currentPage = 1): LengthAwarePaginator
    {
        return $this->shiftInterface->getDailyReports(date: $date, employee: $employee, pagination: $pagination, currentPage: $currentPage);
    }

    public function getTimeSheetReports($startDate, $endDate, $employee, $pagination = 50): LengthAwarePaginator
    {
        $reports = $this->shiftInterface->getTimeSheetReports(startDate: $startDate, endDate: $endDate, employee: $employee, pagination: $pagination);

        $reports->getCollection()->transform(function ($user) {
            $user->total_hours_formatted = $this->formatMinutesToTime($user->total_work_minutes);
            $user->total_break_formatted = $this->formatMinutesToTime($user->total_break_minutes);
            $user->avg_daily_hours_formatted = $this->formatMinutesToTime($user->average_daily_hours);
            $user->avg_break_formatted = $this->formatMinutesToTime($user->average_break_time);

            return $user;
        });

        return $reports;
    }

    private function formatMinutesToTime(?int $minutes): string
    {
        if ($minutes === null || $minutes === 0) {
            return '00:00';
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        return sprintf('%d hrs %d mins', $hours, $remainingMinutes);
    }

    public function setPresenceStatus($userId, $data)
    {
        return $this->shiftInterface->setPresenceStatus($userId, $data);
    }
}
