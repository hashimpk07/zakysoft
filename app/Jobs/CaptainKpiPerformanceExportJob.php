<?php

namespace App\Jobs;

use App\Captain;
use App\CaptainWorkingLog;
use App\Exports\QueueExport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CaptainKpiPerformanceExportJob extends QueueExport
{
    protected int $chunk = 1000;

    protected string $file_name = 'captain_KPI_performance';

    /**
     * Execute the job.
     */

    public function data(): array
    {
        $request = $this->export->filters;
        $data = [];
        $performance_reports = $this->getReport();
        foreach ($performance_reports as $performance_report) {
            $data[] = [
                $request['from_date'] ?? now()->subDays(6)->format('Y-m-d'),
                $request['to_date'] ?? now()->format('Y-m-d'),
                $performance_report->captain_name,
                $performance_report->work_regions ?? 'N/A',
                $performance_report->company_name ?? 'N/A' ,
                $performance_report->commission_rule_name ?? 'N/A',
                $performance_report->working_days,
                $performance_report->productive_days,
                secondsToTime($performance_report->total_seconds_worked),
                $performance_report->working_days > 0 ? secondsToTime($performance_report->total_seconds_worked / $performance_report->working_days) : '',
                number_format($performance_report->received),
                number_format($performance_report->accepted),
                number_format($performance_report->try_to_accept),
                number_format($performance_report->rejected),
                number_format($performance_report->expired),
                number_format($performance_report->received ? ( ( $performance_report->accepted + $performance_report->try_to_accept) / $performance_report->received ) * 100 : 0, 2),
                number_format($performance_report->delivered),
                $performance_report->sar_value ?? 0 ,
                number_format($performance_report->cancelled),
                number_format($performance_report->returned),
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

        $baseLogs = CaptainWorkingLog::query()
            ->select('captain_id')
            ->addSelect([
                DB::raw('SUM(seconds_worked) as total_seconds_worked'),
                DB::raw('SUM(orders_received) as total_orders_received'),
                DB::raw('SUM(orders_accepted) as total_orders_accepted'),
                DB::raw('SUM(orders_try_to_accept) as total_orders_try_to_accept'),
                DB::raw('SUM(orders_rejected) as total_orders_rejected'),
                DB::raw('SUM(orders_expired) as total_orders_expired'),
                DB::raw('SUM(orders_delivered) as total_orders_delivered'),
                DB::raw('SUM(orders_returned) as total_orders_returned'),
                DB::raw('SUM(orders_cancelled) as total_orders_cancelled'),
                DB::raw('COUNT(DISTINCT date) as working_days'),
                DB::raw('SUM(CASE WHEN orders_delivered > 0 THEN 1 ELSE 0 END) as productive_days'),
            ])
            ->when($q, fn ($query) => $query->where(function ($q2) use ($q) {
                $q2->whereHas('captain.user', fn ($sub) => $sub->where('name', 'like', "%$q%"))
                    ->orWhereHas('captain.user', fn ($sub) => $sub->where('email', 'like', "%$q%"))
                    ->orWhereHas('captain', fn ($sub) => $sub->where('code', 'like', "%$q%"))
                    ->orWhereHas('captain', fn ($sub) => $sub->where('iqama_number', 'like', "%$q%"));
            }))
            ->when($captain, fn ($query) => $query->whereIn('captain_id', $captain))
            ->when($status, fn ($query) => $query->whereHas('captain', fn ($q) => $q->where('status', $status)))
            ->when($quadrants, fn ($query) => $query->whereHas('captain.regions.quadrant', fn ($q) => $q->whereIn('quadrants.id', $quadrants)))
            ->when($region, fn ($query) => $query->whereHas('captain.regions', fn ($q) => $q->whereIn('regions.id', $region)))
            ->when($employment_type, fn ($query) => $query->whereHas('captain', fn ($q) => $q->where('captain_employment_type_id', $employment_type)))
            ->when($companies, fn ($query) => $query->whereHas('captain.company', fn ($q) => $q->whereIn('third_party_logistic_companies.id', $companies)))
            ->whereHas('captain', fn ($query) => $query->whereIn('status', [Captain::STATUS_ACTIVE, Captain::STATUS_BANNED, Captain::STATUS_INACTIVE]))
            ->whereBetween('date', [$from_date, $to_date])
            ->groupBy('captain_id');

        $commissionSubquery = DB::table('captain_commissions')
            ->select([
                'captain_id',
                DB::raw('SUM(balance) as total_balance'),
                DB::raw('AVG(acceptance_percent) as avg_acceptance'),
                DB::raw('MAX(commission_rule_id) as commission_rule_id')
            ])
            ->where('commission_rule_type', 2)
            ->whereBetween('date', [$from_date, $to_date])
            ->groupBy('captain_id');

        return $performance_reports = DB::query()
            ->fromSub($baseLogs, 'logs')
            ->join('captains as c', 'c.id', '=', 'logs.captain_id')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->joinSub($commissionSubquery, 'cc', 'cc.captain_id', '=', 'logs.captain_id')
            ->leftJoin('commission_rules as cr', 'cr.id', '=', 'cc.commission_rule_id')
            ->leftJoin('captains_third_party_logistic as ctl', 'ctl.captain_id', '=', 'c.id')
            ->leftJoin('third_party_logistic_companies as tplc', 'tplc.id', '=', 'ctl.third_party_logistic_company_id')
            ->leftJoin('captain_region as rc', 'rc.captain_id', '=', 'c.id')
            ->leftJoin('regions as r', 'r.id', '=', 'rc.region_id')
            ->select([
                'logs.captain_id',
                'u.name as captain_name',
                DB::raw('GROUP_CONCAT(DISTINCT r.name) as work_regions'),
                'tplc.name as company_name',
                'cr.name as commission_rule_name',
                'logs.working_days',
                'logs.productive_days',
                'logs.total_seconds_worked',
                // DB::raw('ROUND(logs.total_seconds_worked / 3600, 2) as online_hours'),
                // DB::raw('ROUND(logs.total_seconds_worked / 3600 / logs.working_days, 2) as avg_online_hours'),
                'logs.total_orders_received as received',
                'logs.total_orders_accepted as accepted',
                'logs.total_orders_try_to_accept as try_to_accept',
                'logs.total_orders_rejected as rejected',
                'logs.total_orders_expired as expired',
                'logs.total_orders_delivered as delivered',
                DB::raw('COALESCE(ROUND(cc.avg_acceptance, 2), 0) as acceptance_rate'),
                DB::raw('COALESCE(cc.total_balance, 0) as sar_value'),
                'logs.total_orders_cancelled as cancelled',
                'logs.total_orders_returned as returned',
            ])
            ->groupBy('logs.captain_id', 'u.name', 'tplc.name', 'cr.name', 'logs.working_days', 'logs.productive_days', 
                    'logs.total_seconds_worked', 'logs.total_orders_received', 'logs.total_orders_accepted',
                    'logs.total_orders_try_to_accept', 'logs.total_orders_rejected', 'logs.total_orders_expired',
                    'logs.total_orders_delivered', 'logs.total_orders_cancelled', 'logs.total_orders_returned',
                    'cc.total_balance', 'cc.avg_acceptance')
            ->when((isset($request['sort_by']) && $request['sort_by'] == 'acceptance_rate'), function ($q) use ($request) {
                $order = strtolower($request['sort_order']) === 'desc' ? 'desc' : 'asc';
                $q->orderBy(DB::raw('cc.avg_acceptance'), $order);
            })
            ->when((isset($request['sort_by']) && $request['sort_by'] == 'sar_value'), function ($q) use ($request) {
                $order = strtolower($request['sort_order']) === 'desc' ? 'desc' : 'asc';
                $q->orderBy(DB::raw('cc.total_balance'), $order);
            })
            ->orderBy('captain_id')
            ->limit($this->chunk)
            ->offset($this->chunk * $this->export->page_done ?? 0)
            ->get();
    }

    public function headers(): array
    {
        return [
            'Date From',
            'Date To',
            'Captain Name',
            'Work Region',
            '3PL Company',
            'Assigned Commission',
            'W. Days',
            'P. Days',
            'Online Hours',
            'Avg. O. Hours',
            'Received',
            'Accepted',
            'Try to accept',
            'Rejected',
            'Expired',
            'Acceptance Rate(%)',
            'Delivered Orders',
            'Payable SAR',
            'Cancelled Orders',
            'Returned Orders',
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
        $status = isset($request['status']) ? $request['status'] : false;

        $baseLogs = CaptainWorkingLog::query()
            ->select('captain_id')
            ->addSelect([
                DB::raw('SUM(seconds_worked) as total_seconds_worked'),
                DB::raw('SUM(orders_received) as total_orders_received'),
                DB::raw('SUM(orders_accepted) as total_orders_accepted'),
                DB::raw('SUM(orders_try_to_accept) as total_orders_try_to_accept'),
                DB::raw('SUM(orders_rejected) as total_orders_rejected'),
                DB::raw('SUM(orders_expired) as total_orders_expired'),
                DB::raw('SUM(orders_delivered) as total_orders_delivered'),
                DB::raw('SUM(orders_returned) as total_orders_returned'),
                DB::raw('SUM(orders_cancelled) as total_orders_cancelled'),
                DB::raw('COUNT(DISTINCT date) as working_days'),
                DB::raw('SUM(CASE WHEN orders_delivered > 0 THEN 1 ELSE 0 END) as productive_days'),
            ])
            ->when($q, fn ($query) => $query->where(function ($q2) use ($q) {
                $q2->whereHas('captain.user', fn ($sub) => $sub->where('name', 'like', "%$q%"))
                    ->orWhereHas('captain.user', fn ($sub) => $sub->where('email', 'like', "%$q%"))
                    ->orWhereHas('captain', fn ($sub) => $sub->where('code', 'like', "%$q%"))
                    ->orWhereHas('captain', fn ($sub) => $sub->where('iqama_number', 'like', "%$q%"));
            }))
            ->when($captain, fn ($query) => $query->whereIn('captain_id', $captain))
            ->when($status, fn ($query) => $query->whereHas('captain', fn ($q) => $q->where('status', $status)))
            ->when($quadrants, fn ($query) => $query->whereHas('captain.regions.quadrant', fn ($q) => $q->whereIn('quadrants.id', $quadrants)))
            ->when($region, fn ($query) => $query->whereHas('captain.regions', fn ($q) => $q->whereIn('regions.id', $region)))
            ->when($employment_type, fn ($query) => $query->whereHas('captain', fn ($q) => $q->where('captain_employment_type_id', $employment_type)))
            ->when($companies, fn ($query) => $query->whereHas('captain.company', fn ($q) => $q->whereIn('third_party_logistic_companies.id', $companies)))
            ->whereHas('captain', fn ($query) => $query->whereIn('status', [Captain::STATUS_ACTIVE, Captain::STATUS_BANNED, Captain::STATUS_INACTIVE]))
            ->whereBetween('date', [$from_date, $to_date])
            ->groupBy('captain_id');

        $commissionSubquery = DB::table('captain_commissions')
            ->select([
                'captain_id',
                DB::raw('SUM(balance) as total_balance'),
                DB::raw('AVG(acceptance_percent) as avg_acceptance'),
                DB::raw('MAX(commission_rule_id) as commission_rule_id')
            ])
            ->where('commission_rule_type', 2)
            ->whereBetween('date', [$from_date, $to_date])
            ->groupBy('captain_id');

        // Count the number of grouped rows (distinct captains)
        return $totalCount = DB::query()
            ->fromSub($baseLogs, 'logs')
            ->join('captains as c', 'c.id', '=', 'logs.captain_id')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->rightJoinSub($commissionSubquery, 'cc', 'cc.captain_id', '=', 'logs.captain_id')
            ->leftJoin('captains_third_party_logistic as ctl', 'ctl.captain_id', '=', 'c.id')
            ->leftJoin('third_party_logistic_companies as tplc', 'tplc.id', '=', 'ctl.third_party_logistic_company_id')
            ->leftJoin('captain_region as rc', 'rc.captain_id', '=', 'c.id')
            ->leftJoin('regions as r', 'r.id', '=', 'rc.region_id')
            ->select('logs.captain_id')
            ->groupBy('logs.captain_id')
            ->get()
            ->count();
    }
}
