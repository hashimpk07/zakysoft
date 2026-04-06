<?php

namespace App\Jobs;

use App\Captain;
use App\CaptainWorkingLog;
use App\Exports\QueueExport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Log;

class DriverLevelReportExport extends QueueExport
{
    protected int $chunk = 2000;

    protected string $file_name = 'driver-level-report';

    public $timeout = 100000;

    public function data(): array
    {
        $data = [];
        $driver_report_datas = $this->getData();
        if (isset($this->export->filters['non_aggregated']) && $this->export->filters['non_aggregated']) {
            // Non-aggregated data (daily records)
            foreach ($driver_report_datas as $captain_data) {

                // Log::channel('commission')->info('Driver data', [
                //     'captain_id' => $captain_data->captain_id ?? null,
                //     'rule_id' => optional($captain_data->captain)->captain_rule_id,
                //     'rule_name' => optional($captain_data->captain->commissionRule)->name,
                //     'rule' =>$captain_data->captain_rule_name 
                // ]);

                $data[] = [
                    optional($captain_data->captain)->user->name ?? 'N/A',
                    // optional($captain_data->captain->commissionRule)->name ?? 'N/A',
                    $captain_data->captain_rule_name ?? 'N/A',
                    optional($captain_data->captain)->iqama_number ?? 'N/A',
                    optional($captain_data->captain)->code ?? 'N/A',
                    optional(optional($captain_data->captain)->employmentType)->name ?? 'N/A',
                    optional($captain_data->captain)->company->name ?? optional($captain_data->captain->partner)->first_name ?? 'N/A',
                    optional($captain_data->captain)->regions ? $captain_data->captain->regions->pluck('quadrant.name')->unique()->join(', ') : 'N/A',
                    optional($captain_data->captain)->regions ? $captain_data->captain->regions->pluck('name')->unique()->join(', ') : 'N/A',
                    Carbon::parse($captain_data->date)->format('Y-m-d'), // Display the specific date
                    isset($captain_data->seconds_worked) ? secondsToTime($captain_data->seconds_worked) : 'N/A',
                    isset($captain_data->idle_hours) ? secondsToTime($captain_data->idle_hours) : 'N/A',
                    $captain_data->orders_received ?? 0,
                    $captain_data->orders_try_to_accept ?? 'N/A',
                    $captain_data->orders_rejected ?? 'N/A',
                    $captain_data->orders_expired ?? 'N/A',
                    $captain_data->orders_accepted ?? 'N/A',
                    isset($captain_data->orders_received, $captain_data->orders_accepted) && $captain_data->orders_received > 0
                    ? number_format((( $captain_data->orders_accepted +  $captain_data->orders_try_to_accept ) / $captain_data->orders_received ) * 100, 2) . '%'
                    : '100.00%',
                    $captain_data->orders_delivered ?? 0,
                    $captain_data->orders_returned ?? 0,
                    $captain_data->orders_cancelled ?? 0,
                    isset($captain_data->orders_delivered, $captain_data->orders_accepted) && $captain_data->orders_accepted > 0
                    ? number_format(($captain_data->orders_delivered / $captain_data->orders_accepted) * 100, 2) . '%'
                    : '100.00%',
                    isset($captain_data->average_arrival_time) ? secondsToTime($captain_data->average_arrival_time) : 'N/A',
                    isset($captain_data->average_delivery_time) ? secondsToTime($captain_data->average_delivery_time) : 'N/A',
                    isset($captain_data->average_delivery_distance) ? number_format($captain_data->average_delivery_distance, 2) : '0.00',
                ];
            }
        } else {
            // Aggregated data (existing functionality)
            foreach ($driver_report_datas as $captain_data) {

                // Log::channel('commission')->info('Driver data', [
                //     'captain_id' => $captain_data->captain_id ?? null,
                //     'rule_id' => optional($captain_data->captain)->captain_rule_id,
                //     'rule_name' => optional($captain_data->captain->commissionRule)->name,
                //     'rule' =>$captain_data->captain_rule_name 
                // ]);

                $data[] = [
                    optional($captain_data->captain)->user->name ?? 'N/A',
                    $captain_data->captain_rule_name ?? 'N/A',
                    optional($captain_data->captain)->iqama_number ?? 'N/A',
                    optional($captain_data->captain)->code ?? 'N/A',
                    optional(optional($captain_data->captain)->employmentType)->name ?? 'N/A',
                    optional($captain_data->captain)->company->name ?? optional($captain_data->captain->partner)->first_name ?? 'N/A',
                    optional($captain_data->captain)->regions ? $captain_data->captain->regions->pluck('quadrant.name')->unique()->join(', ') : 'N/A',
                    optional($captain_data->captain)->regions ? $captain_data->captain->regions->pluck('name')->unique()->join(', ') : 'N/A',
                    $captain_data->working_days ?? 'N/A',
                    $captain_data->productive_days ?? 'N/A',
                    isset($captain_data->total_seconds_worked) ? secondsToTime($captain_data->total_seconds_worked) : 'N/A',
                    isset($captain_data->total_seconds_worked, $captain_data->working_days) && $captain_data->working_days > 0
                    ? secondsToTime($captain_data->total_seconds_worked / $captain_data->working_days)
                    : 'N/A',
                    isset($captain_data->idle_hours) ? secondsToTime($captain_data->idle_hours) : 'N/A',
                    $captain_data->total_orders_received ?? 0,
                    $captain_data->orders_try_to_accept ?? 'N/A',
                    $captain_data->total_orders_rejected ?? 'N/A',
                    $captain_data->total_orders_expired ?? 'N/A',
                    $captain_data->total_orders_accepted ?? 'N/A',
                    isset($captain_data->total_orders_received, $captain_data->total_orders_accepted) && $captain_data->total_orders_received > 0
                    ? number_format(( ( $captain_data->orders_try_to_accept + $captain_data->total_orders_accepted ) / $captain_data->total_orders_received ) * 100, 2) . '%'
                    : '100.00%',
                    $captain_data->total_orders_delivered ?? 0,
                    $captain_data->total_orders_returned ?? 0,
                    $captain_data->total_orders_cancelled ?? 0,
                    isset($captain_data->total_orders_delivered, $captain_data->total_orders_accepted) && $captain_data->total_orders_accepted > 0
                    ? number_format(($captain_data->total_orders_delivered / $captain_data->total_orders_accepted) * 100, 2) . '%'
                    : '100.00%',
                    isset($captain_data->average_arrival_time) ? secondsToTime($captain_data->average_arrival_time) : 'N/A',
                    isset($captain_data->average_delivery_time) ? secondsToTime($captain_data->average_delivery_time) : 'N/A',
                    isset($captain_data->average_delivery_distance)
                    ? number_format($captain_data->average_delivery_distance, 2)
                    : '0.00',
                ];
            }
        }

        return $data;
    }

    public function headers(): array
    {
        if (isset($this->export->filters['non_aggregated']) && $this->export->filters['non_aggregated']) {
            // Headers for non-aggregated data
            return [
                'Captain',
                'Captain Assigned Rule',
                'Iqama Number',
                'Employee Id',
                'Employee Type',
                'Employer',
                'Work Area',
                'Work Region',
                'Date',
                'Online Hours',
                'Idle Hours',
                'Received Orders',
                'Try To Accept Orders',
                'Rejected Orders',
                'Expired Orders',
                'Accepted Orders',
                'Acceptance Rate(%)',
                'Delivered Orders',
                'Returned Orders',
                'Cancelled Orders',
                'Success Rate(%)',
                'Average Arrival Time',
                'Average Delivery Time',
                'Average Delivery Distance',
            ];
        }

        // Headers for aggregated data
        return [
            'Captain',
            'Captain Assigned Rule',
            'Iqama Number',
            'Employee Id',
            'Employee Type',
            'Employer',
            'Work Region',
            'Work Area',
            'Working Days',
            'Productive Days',
            'Online Hours',
            'Avg .Online Hours',
            'Avg Idle Hours',
            'Received Orders',
            'Try To Accept Orders',
            'Rejected Orders',
            'Expired Orders',
            'Accepted Orders',
            'Acceptance Rate(%)',
            'Delivered Orders',
            'Returned Orders',
            'Cancelled Orders',
            'Success Rate(%)',
            'Average Arrival Time',
            'Average Delivery Time',
            'Average Delivery Distance',
        ];
    }

    public function getData()
    {
        $request = $this->export->filters;
        $isNonAggregated = isset($request['non_aggregated']) && $request['non_aggregated'];
        $fromDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'])->startOfDay()
            : now()->subDays(6)->startOfDay();

        $toDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'])->endOfDay()
            : now()->endOfDay();

        $q = isset($request['q']) ? $request['q'] : null;
        $captain = isset($request['captain']) ? $request['captain'] : null;
        $status = isset($request['status']) ? $request['status'] : null;
        $region = isset($request['region']) ? $request['region'] : null;
        $regions = isset($request['regions']) ? $request['regions'] : null;
        $areas_id = isset($request['areas_id']) ? $request['areas_id'] : null;
        $employment_type = isset($request['employment_type']) ? $request['employment_type'] : null;
        $job_type = isset($request['job_type']) ? $request['job_type'] : null;
        $quadrants = isset($request['quadrants']) ? $request['quadrants'] : null;
        $companies = isset($request['companies']) ? $request['companies'] : null;

        $query = CaptainWorkingLog::query()
            ->with(
                'captain:captains.id,code,iqama_number,captain_employment_type_id,user_id,sponsor_name,commission_rule_id',
                'captain.commissionRule:id,name',
                'captain.partner:id,first_name',
                'captain.user:id,name',
                'captain.employmentType',
                'captain.company:third_party_logistic_companies.id,name',
                'captain.regions.quadrant:id,name',
            )
            ->when($q, function ($query, $q) {
                return $query->whereLike([
                    'captain.user.name',
                    'captain.code',
                    'captain.user.email',
                    'captain.iqama_number',
                ], $q);
            })
            ->when($captain, function ($query, $captain) {
                return $query->whereHas('captain', function ($query) use ($captain) {
                    $query->whereIn('captains.user_id', $captain);
                });
            })
            ->when($status, function ($query, $status) {
                return $query->whereHas('captain', function ($query) use ($status) {
                    $query->where('status', $status);
                });
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
            ->when($regions, function ($query, $regions) {
                return $query->whereHas('captain.regions', function ($query) use ($regions) {
                    $query->whereIn('regions.id', $regions);
                });
            })
            ->when($areas_id, function ($query, $areas_id) {
                return $query->whereHas('captain.regions.quadrant', function ($query) use ($areas_id) {
                    $query->whereIn('quadrants.id', $areas_id);
                });
            })
            ->when($employment_type, function ($query, $employment_type) {
                return $query->whereHas('captain', function ($query) use ($employment_type) {
                    $query->where('captain_employment_type_id', $employment_type);
                });
            })
            ->when($job_type, function ($query, $job_type) {
                return $query->whereHas('captain', function ($query) use ($job_type) {
                    $query->where('captain_employment_type_id', $job_type);
                });
            })
            ->whereHas('captain', function ($query) {
                $query->whereIn('status', [Captain::STATUS_ACTIVE, Captain::STATUS_BANNED, Captain::STATUS_INACTIVE]);
            })
            ->when($companies, function ($query, $companies) {
                $query->whereHas('captain.company', function ($query) use ($companies) {
                    $query->whereIn('third_party_logistic_companies.id', $companies);
                });
            })
            ->whereBetween('date', [$fromDate, $toDate]);

        if ($isNonAggregated) {
            Log::channel('commission')->info("not aggregate function");
            // Return non-aggregated data (daily data)
            return $query->select('captain_id', 'date', 'seconds_worked', 'orders_received', 'orders_accepted', 
                    'orders_delivered', 'orders_returned', 'orders_cancelled', 'orders_expired', 
                    'orders_reassigned', 'orders_rejected', 'orders_try_to_accept')
                ->addSelect([
                    DB::raw("
                        COALESCE(
                        (
                            SELECT commission_rules.name
                            FROM order_reports
                            JOIN commission_rules 
                                ON order_reports.captain_rule_id = commission_rules.id
                            WHERE order_reports.captain_id = captain_working_logs.captain_id
                            AND DATE(order_reports.final_status_at) = captain_working_logs.date
                            ORDER BY order_reports.final_status_at DESC
                            LIMIT 1
                        ),
                        (
                            SELECT commission_rules.name
                            FROM captains 
                            JOIN commission_rules ON captains.commission_rule_id = commission_rules.id
                            WHERE captains.id = captain_working_logs.captain_id
                            LIMIT 1
                        ),
                        'Not Assigned') AS captain_rule_name
                    "),
                    DB::raw('(SELECT AVG(TIMESTAMPDIFF(SECOND, order_accepted_at, reached_shop_at)) 
                        FROM order_reports 
                        WHERE order_reports.captain_id = captain_working_logs.captain_id 
                        AND DATE(order_reports.final_status_at) = captain_working_logs.date
                    ) as average_arrival_time'),
                    DB::raw('(SELECT AVG(TIMESTAMPDIFF(SECOND, order_picked_at, final_status_at)) 
                        FROM order_reports 
                        WHERE order_reports.captain_id = captain_working_logs.captain_id 
                        AND DATE(order_reports.final_status_at) = captain_working_logs.date
                    ) as average_delivery_time'),
                    DB::raw('(SELECT AVG(shop_to_delivery_km) 
                        FROM order_reports 
                        WHERE order_reports.captain_id = captain_working_logs.captain_id 
                        AND DATE(order_reports.final_status_at) = captain_working_logs.date
                    ) as average_delivery_distance'),
                    DB::raw('(
                        captain_working_logs.seconds_worked - 
                        (SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, order_accepted_at, final_status_at)), 0) 
                            FROM order_reports 
                            WHERE order_reports.captain_id = captain_working_logs.captain_id 
                            AND DATE(order_reports.final_status_at) = captain_working_logs.date
                        )
                    ) AS idle_hours')
                ])
                ->orderBy('captain_id')
                ->orderBy('date')
                ->limit($this->chunk)
                ->offset($this->chunk * $this->export->page_done ?? 0)
                ->get();
        } else {
            Log::channel('commission')->info("aggregate function");
            // Return aggregated data (existing functionality)
            return $query->select('captain_id')
                ->addSelect([
                    DB::raw('SUM(captain_working_logs.seconds_worked) as total_seconds_worked'),
                    DB::raw('SUM(orders_received) as total_orders_received'),
                    DB::raw('SUM(orders_accepted) as total_orders_accepted'),
                    DB::raw('SUM(orders_delivered) as total_orders_delivered'),
                    DB::raw('SUM(orders_returned) as total_orders_returned'),
                    DB::raw('SUM(orders_cancelled) as total_orders_cancelled'),
                    DB::raw('SUM(orders_expired) as total_orders_expired'),
                    DB::raw('SUM(orders_reassigned) as total_orders_reassigned'),
                    DB::raw('SUM(orders_rejected) as total_orders_rejected'),
                    DB::raw('SUM(orders_try_to_accept) as orders_try_to_accept'),
                    DB::raw('SUM(orders_expired) as no_of_no_response_requests'),
                    DB::raw('COUNT(DISTINCT date) as working_days'),
                    DB::raw('SUM(CASE WHEN orders_delivered > 0 THEN 1 ELSE 0 END) as productive_days'),
                    DB::raw("
                        COALESCE(
                        (
                            SELECT commission_rules.name
                            FROM order_reports
                            JOIN commission_rules 
                            ON order_reports.captain_rule_id = commission_rules.id
                            WHERE order_reports.captain_id = captain_working_logs.captain_id
                            AND DATE(order_reports.final_status_at) BETWEEN '{$fromDate}' AND '{$toDate}'
                            ORDER BY order_reports.final_status_at DESC
                            LIMIT 1
                        ),
                        (
                            SELECT commission_rules.name
                            FROM captains 
                            JOIN commission_rules ON captains.commission_rule_id = commission_rules.id
                            WHERE captains.id = captain_working_logs.captain_id
                            LIMIT 1
                        ),
                        'Not Assigned') AS captain_rule_name
                    "),
                    DB::raw('(SELECT AVG(TIMESTAMPDIFF(SECOND, order_accepted_at, reached_shop_at)) 
                        FROM order_reports 
                        WHERE order_reports.captain_id = captain_working_logs.captain_id 
                        AND DATE(order_reports.final_status_at) BETWEEN "' . $fromDate . '" AND "' . $toDate . '"
                    ) as average_arrival_time'),
                    DB::raw('(SELECT AVG(TIMESTAMPDIFF(SECOND, order_picked_at, final_status_at)) 
                        FROM order_reports 
                        WHERE order_reports.captain_id = captain_working_logs.captain_id 
                        AND DATE(order_reports.final_status_at) BETWEEN "' . $fromDate . '" AND "' . $toDate . '"
                    ) as average_delivery_time'),
                    DB::raw('(SELECT AVG(shop_to_delivery_km) 
                        FROM order_reports 
                        WHERE order_reports.captain_id = captain_working_logs.captain_id 
                        AND DATE(order_reports.final_status_at) BETWEEN "' . $fromDate . '" AND "' . $toDate . '"
                    ) as average_delivery_distance'),
                    DB::raw('(
                        SUM(captain_working_logs.seconds_worked) - 
                        (SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, order_accepted_at, final_status_at)), 0) 
                            FROM order_reports 
                            WHERE order_reports.captain_id = captain_working_logs.captain_id 
                            AND DATE(order_reports.final_status_at) BETWEEN "' . $fromDate . '" AND "' . $toDate . '"
                        )
                    ) AS idle_hours')
                ])
                ->groupBy('captain_id')
                ->orderBy('captain_id')
                ->limit($this->chunk)
                ->offset($this->chunk * $this->export->page_done ?? 0)
                ->get();
        }
    }

    public function count(): int
    {
        $request = $this->export->filters;
        $isNonAggregated = isset($request['non_aggregated']) && $request['non_aggregated'];

        $fromDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'])->startOfDay()
            : now()->subDays(6)->startOfDay();

        $toDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'])->endOfDay()
            : now()->endOfDay();

        $q = isset($request['q']) ? $request['q'] : null;
        $captain = isset($request['captain']) ? $request['captain'] : null;
        $status = isset($request['status']) ? $request['status'] : null;
        $region = isset($request['region']) ? $request['region'] : null;
        $regions = isset($request['regions']) ? $request['regions'] : null;
        $areas_id = isset($request['areas_id']) ? $request['areas_id'] : null;
        $employment_type = isset($request['employment_type']) ? $request['employment_type'] : null;
        $job_type = isset($request['job_type']) ? $request['job_type'] : null;
        $quadrants = isset($request['quadrants']) ? $request['quadrants'] : null;
        $companies = isset($request['companies']) ? $request['companies'] : null;

        $query = CaptainWorkingLog::query()
            ->when($q, function ($query, $q) {
                return $query->whereLike([
                    'captain.user.name',
                    'captain.code',
                    'captain.user.email',
                    'captain.iqama_number',
                ], $q);
            })
            ->when($captain, function ($query, $captain) {
                return $query->whereHas('captain', function ($query) use ($captain) {
                    $query->whereIn('captains.user_id', $captain);
                });
            })
            ->when($status, function ($query, $status) {
                return $query->whereHas('captain', function ($query) use ($status) {
                    $query->where('status', $status);
                });
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
            ->when($regions, function ($query, $regions) {
                return $query->whereHas('captain.regions', function ($query) use ($regions) {
                    $query->whereIn('regions.id', $regions);
                });
            })
            ->when($areas_id, function ($query, $areas_id) {
                return $query->whereHas('captain.regions.quadrant', function ($query) use ($areas_id) {
                    $query->whereIn('quadrants.id', $areas_id);
                });
            })
            ->when($employment_type, function ($query, $employment_type) {
                return $query->whereHas('captain', function ($query) use ($employment_type) {
                    $query->where('captain_employment_type_id', $employment_type);
                });
            })
            ->when($job_type, function ($query, $job_type) {
                return $query->whereHas('captain', function ($query) use ($job_type) {
                    $query->where('captain_employment_type_id', $job_type);
                });
            })
            ->whereHas('captain', function ($query) {
                $query->whereIn('status', [Captain::STATUS_ACTIVE, Captain::STATUS_BANNED, Captain::STATUS_INACTIVE]);
            })
            ->when($companies, function ($query, $companies) {
                $query->whereHas('captain.company', function ($query) use ($companies) {
                    $query->whereIn('third_party_logistic_companies.id', $companies);
                });
            })
            ->whereBetween('date', [$fromDate, $toDate]);

        if ($isNonAggregated) {
            // Count non-aggregated records
            return $query->count();
        } else {
            // Count aggregated records (existing functionality)
            return $query->distinct('captain_id')->count('captain_id');
        }
    }
}