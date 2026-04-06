<?php

namespace App\Services\Reports\CaptainReports;

use App\Interfaces\Reports\CaptainReports\CaptainPerformanceReportInterface;
use App\Interfaces\Reports\CaptainReports\PaymentInterface;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class CaptainShiftRuleReportService
{

    public function __construct(protected readonly CaptainPerformanceReportInterface $repository)
    {
    }

    public function getShiftRuleReports(Request $request): LengthAwarePaginator
    {
        $filters = $this->extractFilters($request);
        $date    = $this->resolveDate($request->get('delivery_date'));
        $page    = (int) $request->get('page', 1);

        $paginator = $this->repository->getShiftReports(
            $filters,
            $date->format('Y-m-d'),
            $request->get('per_page', 20),
        );

        $paginator->getCollection()->map(function ($record) {
            return $this->formatRecord($record);
        });

        return $paginator;
    }

    private function extractFilters(Request $request): array
    {
        return [
            'captain_id'  => $request->get('captain'),
            'employee_id' => $request->get('employee_id'),
            'iqama'       => $request->get('iqama'),
            'job_type'    => $request->get('job_type'),
            'nationality' => $request->get('nationality'),
            'shift_rule'  => $request->get('shift_rule'),
        ];
    }

    private function resolveDate(?string $rawDate): Carbon
    {
        if (!$rawDate) {
            $date = now()->subDay();

            if (Carbon::parse($date)->format('H:i:s') < '06:00:00') {
                return Carbon::parse($date)->subDay(1);
            }

            return Carbon::parse($date);
        }

        return Carbon::parse($rawDate);
    }

    private function formatRecord($record): array
    {
        $captain   = $record->captain;
        $shiftName = optional($record->shiftRule)->name ?? '-';

        return [
            'emp_id'   => $captain->code,
            'name'     => optional($captain->user)->name ?? '-',
            'job_type' => optional($captain->employmentType)->name ?? '-',
            'iqama'    => $captain->iqama_number,
            'shift'    => [
                [
                    'rule_name'                  => $shiftName,
                    'shift_a'                    => $record->shift_a,
                    'shift_a_duration'           => $record->shift_duration_a ?? 0,
                    'actual_a_working'           => $record->shift_a_start_time . ' - ' . $record->shift_a_end_time,
                    'actual_a_working_duration'  => $record->captain_working_duration_a,
                    'shift_b'                    => $record->shift_b,
                    'shift_b_duration'           => $record->shift_duration_b ?? '0',
                    'actual_b_working'           => $record->shift_b_start_time && $record->shift_b_end_time
                        ? $record->shift_b_start_time . ' - ' . $record->shift_b_end_time
                        : 'N/A',
                    'actual_b_working_duration'  => $record->captain_working_duration_b ?? '0',
                    'total_duration'             => $record->total_duration ?? '0',
                ],
            ],
        ];
    }
}