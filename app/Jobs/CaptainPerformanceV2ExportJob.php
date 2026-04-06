<?php

namespace App\Jobs;

use App\Captain;
use App\CaptainWorkingLog;
use App\Exports\QueueExport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CaptainPerformanceV2ExportJob extends QueueExport
{
    protected int $chunk = 1000;

    protected string $file_name = 'captain performance';

    /**
     * Execute the job.
     */

    public function data(): array
    {
        $data = [];
        $performance_reports = $this->getReport();
        foreach ($performance_reports as $performance_report) {
            $data[] = [
                $performance_report->captain->user->name,
                $performance_report->captain->iqama_number,
                $performance_report->captain->code,
                $performance_report->captain->company->name ?? ($performance_report->captain->employmentType->name ?? ""),
                $performance_report->captain->regions->pluck('name')->unique()->join(', '),
                $performance_report->captain->regions->pluck('quadrant.name')->unique()->join(', '),
                $performance_report->working_days,
                $performance_report->productive_days,
                secondsToTime($performance_report->total_seconds_worked),
                $performance_report->working_days > 0 ? secondsToTime($performance_report->total_seconds_worked / $performance_report->working_days) : '',
                number_format($performance_report->total_orders_received),
                number_format($performance_report->orders_try_to_accept),
                number_format($performance_report->total_orders_rejected),
                number_format($performance_report->no_of_no_response_requests),
                number_format($performance_report->total_orders_accepted),
                ($performance_report->total_orders_received > 0 ? number_format(( ( $performance_report->total_orders_accepted + $performance_report->orders_try_to_accept )/ $performance_report->total_orders_received) * 100, 2) : 0) . "%",
                number_format($performance_report->total_orders_delivered),
                number_format($performance_report->total_orders_returned),
                number_format($performance_report->total_orders_cancelled),
                ($performance_report->total_orders_accepted > 0 ? number_format(($performance_report->total_orders_delivered / $performance_report->total_orders_accepted) * 100, 2) : 0) . "%",
            ];
        }

        return $data;
    }

    public function getReport()
    {
        $request = $this->export->filters;

        $from_date = isset($request['from_date']) ? Carbon::parse($request['from_date'])->format('Y-m-d 00:00:00') : Carbon::now()->subDays(6)->format('Y-m-d 00:00:00');
        $to_date = isset($request['to_date']) ? Carbon::parse($request['to_date'])->format('Y-m-d 23:59:59') : Carbon::now()->format('Y-m-d 23:59:59');

        // $from_date = Carbon::parse($from_date)->setTime(6, 0, 0);
        // $to_date = Carbon::parse($to_date)->addDay()->setTime(5, 59, 59);
        $region = isset($request['areas_id']) ? $request['areas_id'] : false;
        $captain = isset($request['captain']) ? $request['captain'] : '';
        $q = isset($request['q']) ? $request['q'] : '';
        $sort_by = isset($request['sort_by']) ? $request['sort_by'] : '';
        $sort_order = isset($request['sort_order']) ? $request['sort_order'] : 'asc';
        $employment_type = isset($request['employment_type']) ? $request['employment_type'] : '';
        $companies = isset($request['companies']) ? $request['companies'] : false;
        $quadrants = isset($request['regions']) ? $request['regions'] : false;
        $status = isset($request['status']) ? $request['status'] : false;



        return CaptainWorkingLog::query()
            ->with(
                'captain:captains.id,code,iqama_number,captain_employment_type_id,user_id',
                'captain.user:id,name',
                'captain.employmentType',
                'captain.company:third_party_logistic_companies.name',
                'captain.regions:id,name,quadrant_id',
                'captain.regions.quadrant:id,name',
            )
            ->select('captain_id')
            ->addSelect([
                DB::raw('SUM(seconds_worked) as total_seconds_worked'),
                DB::raw('SUM(orders_received) as total_orders_received'),
                DB::raw('SUM(orders_accepted) as total_orders_accepted'),
                DB::raw('SUM(orders_delivered) as total_orders_delivered'),
                DB::raw('SUM(orders_returned) as total_orders_returned'),
                DB::raw('SUM(orders_cancelled) as total_orders_cancelled'),
                DB::raw('SUM(orders_reassigned) as total_orders_reassigned'),
                DB::raw('SUM(orders_rejected) as total_orders_rejected'),
                DB::raw('SUM(orders_try_to_accept) as orders_try_to_accept'),
                DB::raw('SUM(orders_expired) as no_of_no_response_requests'),
                DB::raw('COUNT(DISTINCT date) as working_days'),
                DB::raw('SUM(CASE WHEN orders_delivered > 0 THEN 1 ELSE 0 END) as productive_days')
            ])
            ->when($q, function ($query, $q) {
                return $query->whereLike([
                    'captain.user.name',
                    'captain.code',
                    'captain.user.email',
                    'captain.iqama_number',
                ], $q);
            })
            ->when($captain, function ($query, $captain) {
                return $query->whereIn('captain_id', $captain);
            })
            ->when($quadrants, function ($query, $quadrants) {
                return $query->whereHas('captain.regions.quadrant', function ($query) use ($quadrants) {
                    $query->whereIn('quadrants.id', $quadrants);
                });
            })
            ->when($region, function ($query, $region) {
                return $query->whereHas('captain.regions', function ($query) use ($region) {
                    $query->whereIn('regions.id', $region);
                });
            })
            ->when($employment_type, function ($query, $employment_type) {
                return $query->whereHas('captain', function ($query) use ($employment_type) {
                    $query->where('captain_employment_type_id', $employment_type);
                });
            })
            ->when($status, function ($query, $status) {
                return $query->whereHas('captain', function ($query) use ($status) {
                    $query->where('status', $status);
                });
            })
            ->when($companies, function ($query, $companies) {
                return $query->whereHas('captain.company', function ($query) use ($companies) {
                    $query->whereIn('third_party_logistic_companies.id', $companies);
                });
            })
            ->whereHas('captain', function ($query) {
                $query->whereIn('status', [Captain::STATUS_ACTIVE, Captain::STATUS_BANNED, Captain::STATUS_INACTIVE]);
            })
            ->whereBetween('date', [$from_date, $to_date])
            ->groupBy('captain_id')
            ->when($sort_by, function ($query, $by) use ($sort_order) {
                $order = $sort_order;
                $order = strtolower($order) == 'asc' ? 'asc' : 'desc';

                if ($by == 'acceptance_rate') {
                    $query->orderByRaw('total_orders_accepted / total_orders_received ' . $order);
                }

                if ($by == 'success_rate') {
                    $query->orderByRaw('total_orders_delivered / total_orders_received ' . $order);
                }
            })
            ->orderBy('captain_id')
            ->limit($this->chunk)
            ->offset($this->chunk * $this->export->page_done ?? 0)
            ->get();
    }

    public function headers(): array
    {
        return [
            'Captain',
            'Iqama Number',
            'Employee Id',
            'Employee Type',
            'Work Area',
            'Work Region',
            'Working Days',
            'Productive Days',
            'Online Hours',
            'Avg. OH',
            'Received Orders',
            'Try to accept orders',
            'Rejected orders',
            'Expired orders',
            'Accepted Orders',
            'Acceptance Rate(%)',
            'Delivered Orders',
            'Returned Orders',
            'Canceled Orders',
            'Success Rate(%)'
        ];
    }

    public function count(): int
    {
        $request = $this->export->filters;

        $from_date = isset($request['from_date']) ? Carbon::parse($request['from_date'])->format('Y-m-d 00:00:00') : Carbon::now()->subDays(6)->format('Y-m-d 00:00:00');
        $to_date = isset($request['to_date']) ? Carbon::parse($request['to_date'])->format('Y-m-d 23:59:59') : Carbon::now()->format('Y-m-d 23:59:59');

        // $from_date = Carbon::parse($from_date)->setTime(6, 0, 0);
        // $to_date = Carbon::parse($to_date)->addDay()->setTime(5, 59, 59);
        $region = isset($request['region']) ? $request['region'] : '';
        $captain = isset($request['captain']) ? $request['captain'] : '';
        $q = isset($request['q']) ? $request['q'] : '';
        $employment_type = isset($request['employment_type']) ? $request['employment_type'] : '';
        $companies = isset($request['companies']) ? $request['companies'] : false;
        $quadrants = isset($request['quadrants']) ? $request['quadrants'] : false;

        return CaptainWorkingLog::query()
            ->when($q, function ($query, $q) {
                return $query->whereLike([
                    'captain.user.name',
                    'captain.code',
                    'captain.user.email',
                    'captain.iqama_number',
                ], $q);
            })
            ->when($captain, function ($query, $captain) {
                return $query->whereIn('captain_id', $captain);
            })
            ->when($quadrants, function ($query, $quadrants) {
                return $query->whereHas('captain.regions.quadrant', function ($query) use ($quadrants) {
                    $query->whereIn('quadrants.id', $quadrants);
                });
            })
            ->when($region, function ($query, $region) {
                return $query->whereHas('captain.regions', function ($query) use ($region) {
                    $query->whereIn('regions.id', $region);
                });
            })
            ->when($employment_type, function ($query, $employment_type) {
                return $query->whereHas('captain', function ($query) use ($employment_type) {
                    $query->where('captain_employment_type_id', $employment_type);
                });
            })
            ->when($companies, function ($query, $companies) {
                return $query->whereHas('captain.company', function ($query) use ($companies) {
                    $query->whereIn('third_party_logistic_companies.id', $companies);
                });
            })
            ->whereHas('captain', function ($query) {
                $query->whereIn('status', [Captain::STATUS_ACTIVE, Captain::STATUS_BANNED, Captain::STATUS_INACTIVE]);
            })
            ->whereBetween('date', [$from_date, $to_date])
            ->groupBy('captain_id')
            ->count();
    }

}
