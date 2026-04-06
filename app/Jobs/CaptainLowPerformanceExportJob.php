<?php

namespace App\Jobs;

use App\Captain;
use App\Exports\QueueExport;
use App\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CaptainLowPerformanceExportJob extends QueueExport
{
    protected int $chunk = 1000;

    protected string $file_name = 'Captain Low Performance';

    /**
     * Execute the job.
     */

    public function getBusinessDayDate($date = null)
    {
        if ($date) {
            return Carbon::parse($date);
        }
        $now = now();
        // If before 6:00 AM, use yesterday
        if ($now->hour < 6) {
            return $now->subDay();
        }
        return $now;
    }


    public function data(): array
    {
        $data = [];
        $performance_reports = $this->getReport();
        foreach ($performance_reports as $performance_report) {
            $data[] = [
                $performance_report->full_name,
                $performance_report->company->name ?? ($performance_report->employmentType->name ?? 'N/A'),
                $performance_report->regions ? $performance_report->regions->pluck('quadrant.name')->unique()->join(', ') : 'N/A',
                $performance_report->regions ? $performance_report->regions->pluck('name')->unique()->join(', ') : 'N/A',
                secondsToTime($performance_report->total_work_time_in_seconds),
                number_format($performance_report->no_of_orders_sent),
                number_format($performance_report->no_of_orders_accepted),
                number_format($performance_report->no_of_completed_orders),

            ];
        }

        return $data;
    }

    public function getReport()
    {
        $request = $this->export->filters;
        $businessDay = $this->getBusinessDayDate($request['date'] ?? null);

        $from_date = $businessDay->copy()->setTime(6, 0, 0);
        $to_date = $businessDay->copy()->addDay()->setTime(5, 59, 59);

        $region = isset($request['areas_id']) ? $request['areas_id'] : false;
        $captain = isset($request['captain']) ? $request['captain'] : '';

        $q = isset($request['q']) ? $request['q'] : '';
        $employment_type = isset($request['job_type']) ? $request['job_type'] : '';
        $companies = isset($request['companies']) ? $request['companies'] : false;
        $quadrants = isset($request['regions']) ? $request['regions'] : false;

        $performance_reports = Captain::
            online()
            ->with([
                'regions.quadrant',
                'employmentType',
                'company',
            ])
            ->select(
                'captains.id',
                DB::raw('CONCAT(captains.firstname, " ", captains.lastname) as full_name'),
                'captains.code',
                'captains.job_type',
                'captains.iqama_number',
                'captain_employment_type_id'
            )
            ->addSelect(
                DB::raw('(select SUM(TIMESTAMPDIFF(SECOND,shift_statuses.shift_start, IFNULL(shift_statuses.shift_end, now())))
                    FROM shift_statuses
                    WHERE
                        shift_statuses.captain_id = captains.id' .
                    ($from_date ? ' AND shift_statuses.created_at >= "' . now()->parse($from_date)->format('Y-m-d 06:00:00') . '"' : '') .
                    ($to_date ? ' AND shift_statuses.created_at <= "' . now()->parse($to_date)->format('Y-m-d 05:59:59') . '"' : '') .
                    ' GROUP BY shift_statuses.captain_id
                ) as total_work_time_in_seconds')
            )
            ->addSelect(
                DB::raw('(SELECT COUNT(*)
                        FROM (SELECT  COUNT(*)
                            FROM
                                package_delivery_requests
                                LEFT JOIN packages ON packages.id = package_delivery_requests.package_id
                                LEFT JOIN package_orders ON package_orders.package_id = packages.id
                                LEFT JOIN orders ON orders.id = package_orders.order_id
                            WHERE
                                package_delivery_requests.captain_id = captains.id AND orders.id IS NOT NULL' .
                    ($from_date ? ' AND orders.created_at >= "' . now()->parse($from_date)->format('Y-m-d 06:00:00') . '"' : '') .
                    ($to_date ? ' AND orders.created_at <= "' . now()->parse($to_date)->format('Y-m-d 05:59:59') . '"' : '') .
                    // ($client ? ' AND orders.client_id = ' . $client : '') .
                    ' GROUP BY orders.id
                        ) as no_of_times_orders_sent
                    ) as no_of_orders_sent')
            )
            ->addSelect(
                DB::raw('(SELECT COUNT(*)
                    FROM (SELECT  COUNT(*)
                        FROM
                            packages
                            LEFT JOIN package_orders ON package_orders.package_id = packages.id
                            LEFT JOIN orders ON orders.id = package_orders.order_id
                        WHERE
                        packages.captain_id = captains.id AND orders.id IS NOT NULL' .
                    ($from_date ? ' AND orders.created_at >= "' . now()->parse($from_date)->format('Y-m-d 06:00:00') . '"' : '') .
                    ($to_date ? ' AND orders.created_at <= "' . now()->parse($to_date)->format('Y-m-d 05:59:59') . '"' : '') .
                    // ($client ? ' AND orders.client_id = ' . $client : '') .
                    ' GROUP BY orders.id
                    ) as no_of_times_orders_accepted
                ) as no_of_orders_accepted')
            )
            ->addSelect(
                DB::raw('(SELECT  COUNT(*)
                        FROM
                            orders
                        WHERE
                            orders.captain_id = captains.id AND
                            orders.status_id = ' . OrderStatus::DELIVERED .
                    ($from_date ? ' AND orders.created_at >= "' . now()->parse($from_date)->format('Y-m-d 06:00:00') . '"' : '') .
                    ($to_date ? ' AND orders.created_at <= "' . now()->parse($to_date)->format('Y-m-d 05:59:59') . '"' : '') .
                    // ($client ? ' AND orders.client_id = ' . $client : '') .
                    ') as no_of_completed_orders')
            )
            ->when($captain, function ($query, $captain) {
                return $query->whereIn('captains.id', $captain);
            })
            ->when($quadrants, function ($query, $quadrants) {
                return $query->whereHas('regions.quadrant', function ($query) use ($quadrants) {
                    $query->whereIn('quadrants.id', $quadrants);
                });
            })
            ->when($region, function ($query, $region) {
                return $query->whereHas('regions', function ($query) use ($region) {
                    $query->whereIn('regions.id', $region);
                });
            })
            ->when($employment_type, function ($query, $employment_type) {
                return $query->whereIn('captain_employment_type_id', $employment_type);
            })
            ->whereIn('status', [Captain::STATUS_ACTIVE])
            ->when($q, function ($query, $q) {
                return $query->whereLike([
                    'user.name',
                    'code',
                    'user.email',
                    'iqama_number',
                ], $q);
            })
            ->when($companies, function ($query, $companies) {
                return $query->whereHas('company', function ($query) use ($companies) {
                    $query->whereIn('third_party_logistic_companies.id', $companies);
                });
            })
            ->orderBy('captains.id')
            ->havingRaw('IFNULL(no_of_orders_sent, 0) < 5 AND IFNULL(no_of_orders_sent, 0) / (IFNULL(total_work_time_in_seconds, 1) / 3600) < 1')
            ->limit($this->chunk)
            ->offset($this->chunk * $this->export->page_done ?? 0)
            ->get();

        return $performance_reports;

    }

    public function headers(): array
    {
        return [
            'Captain',
            'Employment Name',
            'Region',
            'Area',
            'Online Hours',
            'Received',
            'Accepted',
            'Delivered Orders',
        ];
    }

    public function count(): int
    {
        $request = $this->export->filters;

        $request = $this->export->filters;
        $businessDay = $this->getBusinessDayDate($request['date'] ?? null);

        $from_date = $businessDay->copy()->setTime(6, 0, 0);
        $to_date = $businessDay->copy()->addDay()->setTime(5, 59, 59);

        $region = isset($request['region']) ? $request['region'] : '';
        $captain = isset($request['captain']) ? $request['captain'] : '';
        $q = isset($request['q']) ? $request['q'] : '';
        $employment_type = isset($request['employment_type']) ? $request['employment_type'] : '';
        $companies = isset($request['companies']) ? $request['companies'] : false;
        $quadrants = isset($request['quadrants']) ? $request['quadrants'] : false;

        return Captain::
            online()
            ->with([
                'regions.quadrant',
                'employmentType',
                'company',
            ])
            ->select(
                'captains.id',
                DB::raw('CONCAT(captains.firstname, " ", captains.lastname) as full_name'),
                'captains.code',
                'captains.job_type',
                'captains.iqama_number',
                'captain_employment_type_id'
            )
            ->addSelect(
                DB::raw('(select SUM(TIMESTAMPDIFF(SECOND,shift_statuses.shift_start, IFNULL(shift_statuses.shift_end, now())))
                    FROM shift_statuses
                    WHERE
                        shift_statuses.captain_id = captains.id' .
                    ($from_date ? ' AND shift_statuses.created_at >= "' . now()->parse($from_date)->format('Y-m-d 00:00:00') . '"' : '') .
                    ($to_date ? ' AND shift_statuses.created_at <= "' . now()->parse($to_date)->format('Y-m-d 23:59:59') . '"' : '') .
                    ' GROUP BY shift_statuses.captain_id
                ) as total_work_time_in_seconds')
            )
            ->addSelect(
                DB::raw('(SELECT COUNT(*)
                        FROM (SELECT  COUNT(*)
                            FROM
                                package_delivery_requests
                                LEFT JOIN packages ON packages.id = package_delivery_requests.package_id
                                LEFT JOIN package_orders ON package_orders.package_id = packages.id
                                LEFT JOIN orders ON orders.id = package_orders.order_id
                            WHERE
                                package_delivery_requests.captain_id = captains.id AND orders.id IS NOT NULL' .
                    ($from_date ? ' AND orders.created_at >= "' . now()->parse($from_date)->format('Y-m-d 00:00:00') . '"' : '') .
                    ($to_date ? ' AND orders.created_at <= "' . now()->parse($to_date)->format('Y-m-d 23:59:59') . '"' : '') .
                    ' GROUP BY orders.id
                        ) as no_of_times_orders_sent
                    ) as no_of_orders_sent')
            )
            ->addSelect(
                DB::raw('(SELECT COUNT(*)
                    FROM (SELECT  COUNT(*)
                        FROM
                            packages
                            LEFT JOIN package_orders ON package_orders.package_id = packages.id
                            LEFT JOIN orders ON orders.id = package_orders.order_id
                        WHERE
                        packages.captain_id = captains.id AND orders.id IS NOT NULL' .
                    ($from_date ? ' AND orders.created_at >= "' . now()->parse($from_date)->format('Y-m-d 00:00:00') . '"' : '') .
                    ($to_date ? ' AND orders.created_at <= "' . now()->parse($to_date)->format('Y-m-d 23:59:59') . '"' : '') .
                    ' GROUP BY orders.id
                    ) as no_of_times_orders_accepted
                ) as no_of_orders_accepted')
            )
            ->addSelect(
                DB::raw('(SELECT  COUNT(*)
                        FROM
                            orders
                        WHERE
                            orders.captain_id = captains.id AND
                            orders.status_id = ' . OrderStatus::DELIVERED .
                    ($from_date ? ' AND orders.created_at >= "' . now()->parse($from_date)->format('Y-m-d 00:00:00') . '"' : '') .
                    ($to_date ? ' AND orders.created_at <= "' . now()->parse($to_date)->format('Y-m-d 23:59:59') . '"' : '') .
                    // ($client ? ' AND orders.client_id = ' . $client : '') .
                    ') as no_of_completed_orders')
            )
            ->when($captain, function ($query, $captain) {
                return $query->whereIn('captains.id', $captain);
            })
            ->when($quadrants, function ($query, $quadrants) {
                return $query->whereHas('regions.quadrant', function ($query) use ($quadrants) {
                    $query->whereIn('quadrants.id', $quadrants);
                });
            })
            ->when($region, function ($query, $region) {
                return $query->whereHas('regions', function ($query) use ($region) {
                    $query->whereIn('regions.id', $region);
                });
            })
            ->when($employment_type, function ($query, $employment_type) {
                return $query->whereIn('captain_employment_type_id', $employment_type);
            })
            ->whereIn('status', [Captain::STATUS_ACTIVE])
            ->when($q, function ($query, $q) {
                return $query->whereLike([
                    'user.name',
                    'code',
                    'user.email',
                    'iqama_number',
                ], $q);
            })
            ->when($companies, function ($query, $companies) {
                return $query->whereHas('company', function ($query) use ($companies) {
                    $query->whereIn('third_party_logistic_companies.id', $companies);
                });
            })
            ->orderBy('captains.id')
            ->havingRaw('IFNULL(no_of_orders_sent, 0) < 5 AND IFNULL(no_of_orders_sent, 0) / (IFNULL(total_work_time_in_seconds, 1) / 3600) < 1')
            ->get()
            ->count();
    }
}
