<?php

namespace App\Jobs;

use App\GeneralExport;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Exports\QueueExport;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OperationTimesheetReportExport extends QueueExport
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $file_name = 'operation_timesheet_report';

    public function __construct(GeneralExport $export)
    {
        parent::__construct($export);
    }

    // Count distinct users that have at least one daily row in range
    public function count(): int
    {
        $filters = $this->export->filters;

        $fromDate = isset($filters['start_date']) ? Carbon::parse($filters['start_date'])->format('Y-m-d') : now()->subDays(7)->format('Y-m-d');
        $toDate = isset($filters['end_date']) ? Carbon::parse($filters['end_date'])->format('Y-m-d') : now()->format('Y-m-d');
        $employee = $filters['employee'] ?? null;

        $daily = DB::table('employee_shifts')
            ->selectRaw(
                "
                user_id,
                DATE(DATE_SUB(start_time, INTERVAL 6 HOUR)) AS workday
            ",
            )
            ->whereBetween(DB::raw('DATE(DATE_SUB(start_time, INTERVAL 6 HOUR))'), [$fromDate, $toDate])
            ->groupBy('user_id', DB::raw('DATE(DATE_SUB(start_time, INTERVAL 6 HOUR))'));

        $query = User::query()->joinSub($daily, 'daily', fn($j) => $j->on('users.id', '=', 'daily.user_id'));

        if ($employee) {
            $query->where('users.id', $employee);
        }

        return $query->distinct('users.id')->count('users.id');
    }

    public function headers(): array
    {
        return ['Emp ID', 'Employee Name', 'Total Working Days', 'Average Daily Hours', 'Average Break Time', 'Total Hours For Selected Period'];
    }

    public function data(): array
    {
        $filters = $this->export->filters;

        $fromDate = isset($filters['start_date']) ? Carbon::parse($filters['start_date'])->format('Y-m-d') : now()->subDays(7)->format('Y-m-d');
        $toDate = isset($filters['end_date']) ? Carbon::parse($filters['end_date'])->format('Y-m-d') : now()->format('Y-m-d');
        $employee = $filters['employee'] ?? null;

        $limit = $this->chunk;
        $offset = $this->export->page_done * $limit;

        // One row per user per workday with sums
        $daily = DB::table('employee_shifts')
            ->selectRaw(
                "
                user_id,
                DATE(DATE_SUB(start_time, INTERVAL 6 HOUR)) AS workday,
                SUM(total_work_minutes)  AS daily_work_minutes,
                SUM(total_break_minutes) AS daily_break_minutes
            ",
            )
            ->whereBetween(DB::raw('DATE(DATE_SUB(start_time, INTERVAL 6 HOUR))'), [$fromDate, $toDate])
            ->groupBy('user_id', DB::raw('DATE(DATE_SUB(start_time, INTERVAL 6 HOUR))'));

        // Roll-up to user totals/averages
        $query = User::query()->joinSub($daily, 'daily', fn($j) => $j->on('users.id', '=', 'daily.user_id'))->select('users.id', 'users.name', DB::raw('COUNT(DISTINCT daily.workday)            AS total_working_days'), DB::raw('SUM(daily.daily_work_minutes)            AS total_work_minutes'), DB::raw('SUM(daily.daily_break_minutes)           AS total_break_minutes'), DB::raw('ROUND(AVG(daily.daily_work_minutes), 2)  AS average_daily_hours'), DB::raw('ROUND(AVG(daily.daily_break_minutes), 2) AS average_break_time'))->groupBy('users.id', 'users.name');

        if ($employee) {
            $query->where('users.id', $employee);
        }

        $records = $query
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(function ($user) {
                return [$user->id, $user->name, $user->total_working_days, $this->formatMinutesToTime((int) $user->average_daily_hours), $this->formatMinutesToTime((int) $user->average_break_time), $this->formatMinutesToTime((int) $user->total_work_minutes)];
            })
            ->toArray();

        return $records;
    }

    private function formatMinutesToTime(?int $minutes): string
    {
        if ($minutes === null || $minutes === 0) {
            return '00:00';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return sprintf('%d hrs %d mins', $hours, $remainingMinutes);
    }
}
