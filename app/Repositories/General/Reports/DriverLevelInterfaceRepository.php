<?php

namespace App\Repositories\General\Reports;
use App\Interfaces\General\Reports\DriverLevelInterface;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\CaptainWorkingLog;
use App\Captain;


class DriverLevelInterfaceRepository implements DriverLevelInterface
{
    public function getDriverLevelReport(array $filters,int $perPage)
    {
        $from_date = $filters['from_date'];
        $to_date = $filters['to_date'];

        $captain = $filters['captain'] ?? null;
        $status = $filters['status'] ?? null;
        $regions = $filters['regions'] ?? null;
        $quadrants = $filters['quadrants'] ?? null;
        $job_type = $filters['job_type'] ?? null;
        $companies = $filters['companies'] ?? null;
        $q = $filters['q'] ?? null;

        $query = CaptainWorkingLog::query()
            ->with([
                'captain:id,code,iqama_number,captain_employment_type_id,user_id,sponsor_name,commission_rule_id',
                'captain.partner:id,first_name',
                'captain.user:id,name,email',
                'captain.employmentType:id,name',
                'captain.company:third_party_logistic_companies.id,third_party_logistic_companies.name',
                'captain.regions.quadrant:id,name',
            ])

            ->select([
                'captain_id',

                DB::raw('SUM(seconds_worked) as total_seconds_worked'),
                DB::raw('SUM(orders_received) as total_orders_received'),
                DB::raw('SUM(orders_accepted) as total_orders_accepted'),
                DB::raw('SUM(orders_delivered) as total_orders_delivered'),
                DB::raw('SUM(orders_returned) as total_orders_returned'),
                DB::raw('SUM(orders_cancelled) as total_orders_cancelled'),
                DB::raw('SUM(orders_expired) as total_orders_expired'),
                DB::raw('SUM(orders_reassigned) as total_orders_reassigned'),
                DB::raw('SUM(orders_rejected) as total_orders_rejected'),
                DB::raw('SUM(orders_try_to_accept) as orders_try_to_accept'),

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
                        AND DATE(order_reports.final_status_at) BETWEEN '{$from_date}' AND '{$to_date}'
                        ORDER BY order_reports.final_status_at DESC
                        LIMIT 1
                    ),
                    (
                        SELECT commission_rules.name
                        FROM captains 
                        JOIN commission_rules
                        ON captains.commission_rule_id = commission_rules.id
                        WHERE captains.id = captain_working_logs.captain_id
                        LIMIT 1
                    ),
                    'Not Assigned'
                ) as captain_rule_name
                "),

                DB::raw("
                (
                    SELECT AVG(TIMESTAMPDIFF(SECOND, order_accepted_at, reached_shop_at)) 
                    FROM order_reports 
                    WHERE order_reports.captain_id = captain_working_logs.captain_id 
                    AND DATE(order_reports.final_status_at) BETWEEN '{$from_date}' AND '{$to_date}'
                ) as average_arrival_time
                "),

                DB::raw("
                (
                    SELECT AVG(TIMESTAMPDIFF(SECOND, order_picked_at, final_status_at)) 
                    FROM order_reports 
                    WHERE order_reports.captain_id = captain_working_logs.captain_id 
                    AND DATE(order_reports.final_status_at) BETWEEN '{$from_date}' AND '{$to_date}'
                ) as average_delivery_time
                "),

                DB::raw("
                (
                    SELECT AVG(shop_to_delivery_km) 
                    FROM order_reports 
                    WHERE order_reports.captain_id = captain_working_logs.captain_id 
                    AND DATE(order_reports.final_status_at) BETWEEN '{$from_date}' AND '{$to_date}'
                ) as average_delivery_distance
                "),

                DB::raw("
                (
                    SUM(seconds_worked) - 
                    (
                        SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, order_accepted_at, final_status_at)),0)
                        FROM order_reports
                        WHERE order_reports.captain_id = captain_working_logs.captain_id
                        AND DATE(order_reports.final_status_at) BETWEEN '{$from_date}' AND '{$to_date}'
                    )
                ) as idle_seconds
                ")

            ])

            ->whereBetween('date', [$from_date, $to_date])

            ->whereHas('captain', function ($query) {
                $query->whereIn('status', [
                    Captain::STATUS_ACTIVE,
                    Captain::STATUS_BANNED,
                    Captain::STATUS_INACTIVE
                ]);
            })

            ->when($q, function ($query, $q) {
                return $query->whereHas('captain.user', function ($sub) use ($q) {
                    $sub->where('name', 'like', "%$q%")
                        ->orWhere('email', 'like', "%$q%");
                });
            })

            ->when($captain, function ($query, $captain) {
                return $query->whereHas('captain', function ($sub) use ($captain) {
                    $sub->whereIn('captains.user_id', $captain);
                });
            })

            ->when($status, function ($query, $status) {
                return $query->whereHas('captain', function ($sub) use ($status) {
                    $sub->where('status', $status);
                });
            })

            ->when($regions, function ($query, $regions) {
                return $query->whereHas('captain.regions', function ($sub) use ($regions) {
                    $sub->whereIn('regions.id', $regions);
                });
            })

            ->when($quadrants, function ($query, $quadrants) {
                return $query->whereHas('captain.regions.quadrant', function ($sub) use ($quadrants) {
                    $sub->whereIn('quadrants.id', $quadrants);
                });
            })

            ->when($job_type, function ($query, $job_type) {
                return $query->whereHas('captain', function ($sub) use ($job_type) {
                    $sub->where('captain_employment_type_id', $job_type);
                });
            })

            ->when($companies, function ($query, $companies) {
                return $query->whereHas('captain.company', function ($sub) use ($companies) {
                    $sub->whereIn('third_party_logistic_companies.id', $companies);
                });
            })

            ->groupBy('captain_id')
            ->orderBy('captain_id');

        return $query
            ->paginate($perPage)
            ->withQueryString();
    }

}