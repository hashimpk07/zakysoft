<?php

namespace App\Jobs;

use App\CaptainShiftRuleReport;
use App\Exports\QueueExport;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CaptainShiftReportExportJob extends QueueExport
{
    protected int $chunk = 1000;
    protected int $totalData = 0;
    protected string $file_name = 'captain_shift_report';

    public $tries = 3;
    public $timeout = 900;
    public $retryAfter = 60;

    /**
     * Prepare data for Excel export.
     */
    public function data(): array
    {
        try {
            $captainReports = $this->getReport();
           // Log::channel('commission')->info('captainReports: ' . json_encode($captainReports));

            $data = [];
            foreach ($captainReports as $report) {
                $data[] = [
                    $report['emp_id'] ?? '',
                    $report['name'] ?? '',
                    $report['job_type'] ?? '',
                    $report['iqama'] ?? '',
                    $report['shift_name'] ?? '',
                    $report['shift_a_working_hours'] ?? '',
                    $report['shift_a_duration'] ?? '',
                    $report['actual_a_working'] ?? '',
                    $report['actual_a_working_duration'] ?? '',
                    $report['shift_b_working_hours'] ?? '',
                    $report['shift_b_duration'] ?? '',
                    $report['actual_b_working'] ?? '',
                    $report['actual_b_working_duration'] ?? '',
                    $report['total_duration'] ?? '',
                ];
            }

            return $data;
        } catch (\Throwable $e) {
            Log::channel('commission')->error('CaptainShiftReportExportJobs::data failed: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Get captain shift report data with filters.
     */
    private function getReport(): array
    {
        try {
            $request = $this->export->filters;

            $date =  isset($request['delivery_date']) ? $request['delivery_date'] : '';
            $captainId = isset($request['captain']) ? $request['captain'] : '';
            $empId = isset($request['employee_id']) ? $request['employee_id'] : '';
            $iqama = isset($request['iqama']) ? $request['iqama'] : '';
            $jobType = isset($request['job_type']) ? $request['job_type'] : '';
            $shiftRule = isset($request['shift_rule']) ? $request['shift_rule'] : '';
            $nationality = isset($request['nationality']) ? $request['nationality'] : '';

             if (!$date) {
                $date = now()->subDay();
                if (Carbon::parse($date)->format('H:i:s') < '06:00:00') {
                    $date = Carbon::parse($date)->subDay(1);
                }
            } else {
                $date = Carbon::parse($date);
            }
       
           Log::channel('commission')->info("date foramt [date: $date]");

            $query = CaptainShiftRuleReport::with([
                'captain.user:id,name',
                'captain.nationality:id,name',
                'captain.employmentType:id,name',
                'shiftRule:id,name',
            ])->whereDate('date', $date->format('Y-m-d'));

            if ($captainId) $query->where('captain_id', $captainId);
            if ($shiftRule) $query->where('shift_id', $shiftRule);
            if ($jobType) {
                $query->whereHas('captain.employmentType', fn($q) => $q->where('captain_employment_type_id', $jobType));
            }
            if ($nationality) {
                $query->whereHas('captain.nationality', fn($q) => $q->where('id', $nationality));
            }
            if ($iqama) {
                $query->whereHas('captain.user', fn($q) => $q->where('iqama_number', 'like', "%{$iqama}%"));
            }
            if ($empId) {
                $query->whereHas('captain.user', fn($q) => $q->where('code', 'like', "%{$empId}%"));
            }


            $data = $query
                ->orderBy('id', 'desc')
                ->limit($this->chunk)
                ->offset(($this->chunk * ($this->export->page_done ?? 0)))
                ->get()
                ->map(function ($record) {
                    $captain = $record->captain;
                    return [
                        'emp_id' => $captain->code ?? '-',
                        'name' => optional($captain->user)->name ?? '-',
                        'job_type' => optional($captain->employmentType)->name ?? '-',
                        'iqama' => $captain->iqama_number ?? '-',
                        'shift_name' => optional($record->shiftRule)->name ?? '-',
                        'shift_a_working_hours' => $record->shift_a ?? '-',
                        'shift_a_duration' => $record->shift_duration_a ?? '0',
                        'actual_a_working' => $record->shift_a_start_time . ' - ' . $record->shift_a_end_time,
                        'actual_a_working_duration' => $record->captain_working_duration_a ?? '0',
                        'shift_b_working_hours' => $record->shift_b ?? '-',
                        'shift_b_duration' => $record->shift_duration_b ?? '0',
                        'actual_b_working' => $record->shift_b_start_time && $record->shift_b_end_time
                            ? $record->shift_b_start_time . ' - ' . $record->shift_b_end_time
                            : 'N/A',
                        'actual_b_working_duration' => $record->captain_working_duration_b ?? '0',
                        'total_duration' => $record->total_duration ?? '0',
                    ];
                })
                ->toArray();

            $this->totalData = count($data);
            return $data;
        } catch (\Throwable $e) {
            Log::channel('commission')->error('CaptainShiftReportExportJobs::getReport failed: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [];
        }
    }

    /**
     * Excel file headers.
     */
    public function headers(): array
    {
        return [
            'Emp ID',
            'Captain Name',
            'Job Type',
            'Iqama Number',
            'Shift Name',
            'Shift A Working Hours',
            'Shift A Duration',
            'Actual Working Shift A',
            'Actual Duration Shift A',
            'Shift B Working Hours',
            'Shift B Duration',
            'Actual Working Shift B',
            'Actual Duration Shift B',
            'Total Duration',
        ];
    }

    /**
     * Total count of data exported.
     */
    public function count(): int
    {
        return $this->totalData;
    }
}
