<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\GeneralExport;
use App\Shift;
use Carbon\Carbon;

class OperationDailyShiftReportExport extends QueueExport
{
    protected string $file_name = 'operation_daily_report';

    /**
     * Inject dependencies
     */
    public function __construct(GeneralExport $export)
    {
        parent::__construct($export); 
    }

    /**
     * Total rows to export
     */
    public function count(): int
    {
        $filters = $this->export->filters;
        $date = isset($filters['date']) ? Carbon::parse($filters['date']) : now();
        $query = Shift::forBusinessDay($date);

        if (!empty($filters['employee'])) {
            $query->where('user_id', $filters['employee']);
        }

        return $query->count();
    }

    /**
     * CSV headers
     */
    public function headers(): array
    {
        return ['Emp ID', 'Employee Name', 'Start Time', 'End Time', 'Duration', 'Break Time', 'Status'];
    }

    /**
     * Return next chunk of data
     */
    public function data(): array
    {
        // Calculate offset based on current page_done
        $limit = $this->chunk;
        $offset = $this->export->page_done * $limit;
        $filters = $this->export->filters;

        $date = isset($filters['date']) ? Carbon::parse($filters['date']) : now();
        $query = Shift::forBusinessDay($date)->with(['user', 'auditLogs']);

        if (!empty($filters['employee'])) {
            $query->where('user_id', $filters['employee']);
        }

        return $query
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(function ($shift) {
                return [$shift->user->id, $shift->user->name, $shift->start_time->format('d M Y h:i A'), $shift->end_time ? $shift->end_time->format('d M Y h:i A') : 'NA', $shift->total_worked, $shift->total_break, ucwords($shift->status)];
            })
            ->toArray();
    }
}
