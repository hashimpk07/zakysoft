<?php

namespace App\Repositories;

use App\ActivityCheck;
use App\AuditLog;
use App\EmployeeShiftBreak;
use App\Interfaces\ShiftInterface;
use App\PresenceStatus;
use App\Shift;
use App\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShiftInterfaceRepository implements ShiftInterface
{
    public function startShift(): array
    {
        try {
            $presence = $this->getPresenceStatus();
            if ($presence === 'on_duty') {
                return ['error' => 'Already on duty', 'status' => false];
            }

            $isActive = $this->getActiveShift(auth()->id());
            if ($isActive) {
                return ['error' => 'An active shift already exists', 'status' => false];
            }

            DB::beginTransaction();

            $shift = $this->saveShift([
                'user_id' => auth()->id(),
                'start_time' => now(),
                'shift_date' => now()->toDateString(),
            ]);

            $this->setPresenceStatus(auth()->id(), ['status' => 'on_duty', 'active_shift_id' => $shift->id, 'last_activity' => now()]);

            $this->createAuditLog([
                'user_id' => auth()->id(),
                'action' => 'ShiftStarted',
                'meta' => ['shift_id' => $shift->id, 'start_time' => $shift->start_time, 'performed_by' => auth()->id()],
                'shift_id' => $shift->id,
                'performed_by' => auth()->id(),
            ]);

            DB::commit();
            return ['shift' => $shift, 'status' => true];
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Error starting shift: ' . $th->getMessage());
            return ['error' => 'Failed to start shift', 'status' => false];
        }
    }

    public function endShift($shiftId, $userId): array
    {
        try {
            $shift = Shift::find($shiftId);
            if (!$shift || $shift->status !== 'active') {
                return ['error' => 'No active shift found', 'status' => false];
            }
            DB::beginTransaction();

            $hours = now()->diffInMinutes($shift->start_time);

            $shift->end_time = now();
            $shift->total_work_minutes = $hours - ($shift->total_break_minutes ?? 0);
            $shift->status = 'completed';
            $shift->save();

            $performBy = auth()->id() === $userId ? $userId : null;

            $meta = [
                'user_id' => $userId,
                'shift_id' => $shiftId,
                'perform_by' => $performBy ? $performBy : 'system automatically change after 5.59 AM',
                'action_by' => $performBy ? 'user' : 'system',
            ];

            $this->createAuditLog([
                'user_id' => $userId,
                'action' => 'ShiftEnded',
                'meta' => $meta,
                'shift_id' => $shiftId,
                'performed_by' => $performBy,
                'old_status' => 'on_duty',
                'new_status' => 'off_duty',
                'action_by' => $performBy ? 'user' : 'system',
            ]);

            $this->setPresenceStatus($userId, ['status' => 'off_duty', 'active_shift_id' => null, 'last_activity' => now()]);

            DB::commit();

            return ['shift' => $shift, 'status' => true];
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Error ending shift: ' . $th->getMessage());
            return ['error' => 'Failed to end shift', 'status' => false];
        }
    }

    public function startBreak($shiftId): array
    {
        DB::beginTransaction();

        try {
            $break = $this->createBreak([
                'shift_id' => $shiftId,
                'break_start' => now(),
                'status' => 'active',
            ]);

            $this->setPresenceStatus(auth()->id(), ['active_break_id' => $break->id, 'last_activity' => now(), 'status' => 'on_break']);

            $this->createAuditLog([
                'user_id' => auth()->id(),
                'action' => 'BreakStarted',
                'meta' => ['break_id' => $break->id, 'shift_id' => $shiftId, 'break_start' => $break->break_start, 'performed_by' => auth()->id()],
                'shift_id' => $shiftId,
                'performed_by' => auth()->id(),
                'old_status' => 'on_duty',
                'new_status' => 'on_break',
            ]);

            DB::commit();
            return ['break' => $break, 'status' => true];
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Error starting break: ' . $th->getMessage());
            return ['error' => 'Failed to start break', 'status' => false];
        }
    }

    public function endBreak($breakId, $userId): array
    {
        try {
            $break = EmployeeShiftBreak::find($breakId);
            if (!$break || $break->status !== 'active') {
                return ['error' => 'No active break found', 'status' => false];
            }

            $diffInMinutes = now()->diffInMinutes($break->break_start);

            DB::beginTransaction();

            $this->setPresenceStatus($userId, ['active_break_id' => null, 'last_activity' => now(), 'status' => 'on_duty']);

            $performBy = auth()->id() === $userId ? $userId : null;

            $meta = [
                'break_id' => $breakId,
                'shift_id' => $break->shift_id,
                'total_break_minutes' => $diffInMinutes,
                'action' => $performBy ? 'user' : 'system',
                'perform_by' => $performBy,
                'notes' => $performBy ? 'manually done by user' : 'system automatically change after 5.59 AM',
            ];

            $this->createAuditLog([
                'user_id' => $userId,
                'action' => 'BreakEnded',
                'meta' => $meta,
                'performed_by' => $performBy,
                'old_status' => 'on_break',
                'new_status' => 'on_duty',
                'shift_id' => $break->shift_id,
                'action_by' => $performBy ? 'user' : 'system',
            ]);

            $this->updateBreak($breakId, [
                'break_end' => now(),
                'status' => 'completed',
                'break_duration_minutes' => $diffInMinutes,
            ]);

            $shift = $this->getActiveShift($userId);
            if ($shift) {
                $shift->total_break_minutes += $diffInMinutes;
                $shift->save();
            }

            DB::commit();

            return ['status' => true, 'break' => $break->fresh(), 'duration_minutes' => $diffInMinutes];
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Error ending break: ' . $th->getMessage());
            return ['error' => 'Failed to end break', 'status' => false];
        }
    }

    public function getPresenceStatus(): string
    {
        $userId = auth()->id();
        $presence = PresenceStatus::where('user_id', $userId)->first();
        return $presence ? $presence->status : 'off_duty';
    }

    public function setPresenceStatus($userId, $data): Model|PresenceStatus
    {
        return PresenceStatus::updateOrCreate(['user_id' => $userId], $data);
    }

    public function getPresence($userId): Model|PresenceStatus|null
    {
        return PresenceStatus::where('user_id', $userId)->first();
    }

    public function activityCheck($answer, Model|ActivityCheck $activityCheck, $userId): array
    {
        if ($answer == $activityCheck->correct_answer) {
            $activityCheck->delete(); // remove the activity check after a correct answer
            return ['message' => 'Correct answer!', 'answer' => true];
        }

        try {
            DB::beginTransaction();
            $presence = $this->getPresence($userId);

            $shiftId = $presence->active_shift_id;

            $break = $this->createBreak([
                'shift_id' => $shiftId,
                'break_start' => now(),
                'status' => 'active',
            ]);

            $this->setPresenceStatus($userId, ['active_break_id' => $break->id, 'last_activity' => now(), 'status' => 'on_break']);

            $this->createAuditLog([
                'user_id' => $userId,
                'action' => 'BreakStarted',
                'meta' => ['break_id' => $break->id, 'shift_id' => $shiftId, 'break_start' => $break->break_start, 'performed_by' => 'system', 'performed_due_to' => 'failed_activity_check', 'user_id' => $userId],
                'shift_id' => $shiftId,
                'old_status' => 'on_duty',
                'new_status' => 'on_break',
                'action_by' => 'system',
            ]);

            $activityCheck->delete(); // wipe out the failed activity check after the break is started

            DB::commit();

            return ['message' => 'Incorrect answer. The break starts now.', 'answer' => false];
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Error processing activity check failure: ' . $th->getMessage());
            return ['error' => 'Failed to process activity check', 'answer' => false];
        }
    }

    private function saveShift($data): Model|Shift
    {
        return Shift::create($data);
    }

    private function createAuditLog($data): Model|AuditLog
    {
        return AuditLog::create($data);
    }

    private function getActiveShift($userId): ?Shift
    {
        return Shift::where('user_id', $userId)->active()->first();
    }

    private function createBreak($data): Model|EmployeeShiftBreak
    {
        return EmployeeShiftBreak::create($data);
    }

    private function updateBreak($breakId, $data): int
    {
        return EmployeeShiftBreak::where('id', $breakId)->update($data);
    }

    public function getDailyReports($date, $employee = null, $pagination, $currentPage): LengthAwarePaginator
    {
        $query = Shift::forBusinessDay($date);

        if ($employee) {
            $query->where('user_id', $employee);
        }

        return $query
            ->with(['user', 'auditLogs'])
            ->paginate($pagination)
            ->withQueryString();
    }

    public function getTimesheetReports($startDate, $endDate, $employee = null, $pagination): LengthAwarePaginator
    {
        // Workday date keys (no time): e.g., 2025-10-27 maps to business day 06:00(27) → 05:59(28)
        $startWorkday = Carbon::parse($startDate)->format('Y-m-d');
        $endWorkday = Carbon::parse($endDate)->format('Y-m-d');

        // Subquery: one row per user per workday using 6-hour shift
        $daily = DB::table('employee_shifts')
            ->selectRaw(
                "
            user_id,
            DATE(DATE_SUB(start_time, INTERVAL 6 HOUR)) AS workday,
            SUM(total_work_minutes)  AS daily_work_minutes,
            SUM(total_break_minutes) AS daily_break_minutes
        ",
            )
            ->whereBetween(DB::raw('DATE(DATE_SUB(start_time, INTERVAL 6 HOUR))'), [$startWorkday, $endWorkday])
            ->groupBy('user_id', DB::raw('DATE(DATE_SUB(start_time, INTERVAL 6 HOUR))'));

        $query = User::query()->joinSub($daily, 'daily', fn($j) => $j->on('users.id', '=', 'daily.user_id'))->select('users.id', 'users.name', 'users.email', DB::raw('COUNT(DISTINCT daily.workday)            AS total_working_days'), DB::raw('SUM(daily.daily_work_minutes)            AS total_work_minutes'), DB::raw('SUM(daily.daily_break_minutes)           AS total_break_minutes'), DB::raw('ROUND(AVG(daily.daily_work_minutes), 2)  AS average_daily_hours'), DB::raw('ROUND(AVG(daily.daily_break_minutes), 2) AS average_break_time'))->groupBy('users.id', 'users.name', 'users.email');

        if ($employee) {
            $query->where('users.id', $employee);
        }

        return $query->paginate($pagination)->withQueryString();
    }
}
