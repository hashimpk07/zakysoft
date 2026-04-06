<?php

namespace App\Repositories\Reports\CaptainReports;

use App\Captain;
use App\CaptainPerformanceReport;
use App\CaptainShiftRuleReport;
use App\CaptainWorkingLog;
use App\CommissionReport;
use App\Interfaces\Reports\CaptainReports\CaptainPerformanceReportInterface;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CaptainPerformanceReportRepository implements CaptainPerformanceReportInterface
{
    public function getPerformanceReports(array $filters, array $dateRange): LengthAwarePaginator
    {
        [$fromDate, $toDate, $fromRaw, $toRaw] = $dateRange;
 
        $perPage = isset($filters['per_page']) && is_numeric($filters['per_page'])
            ? (int) $filters['per_page']
            : 20;
 
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
            ->when($filters['q'] ?? null, fn($query, $q) => $query->where(function ($q2) use ($q) {
                $q2->whereHas('captain.user', fn($sub) => $sub->where('name', 'like', "%$q%"))
                    ->orWhereHas('captain.user', fn($sub) => $sub->where('email', 'like', "%$q%"))
                    ->orWhereHas('captain', fn($sub) => $sub->where('code', 'like', "%$q%"))
                    ->orWhereHas('captain', fn($sub) => $sub->where('iqama_number', 'like', "%$q%"));
            }))
            ->when($filters['captain']         ?? null, fn($q, $v) => $q->whereIn('captain_id', (array) $v))
            ->when($filters['status']          ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('status', $v)))
            ->when($filters['regions']         ?? null, fn($q, $v) => $q->whereHas('captain.regions.quadrant', fn($q) => $q->whereIn('quadrants.id', (array) $v)))
            ->when($filters['areas_id']        ?? null, fn($q, $v) => $q->whereHas('captain.regions', fn($q) => $q->whereIn('regions.id', (array) $v)))
            ->when($filters['employment_type'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('captain_employment_type_id', $v)))
            ->when($filters['companies']       ?? null, fn($q, $v) => $q->whereHas('captain.company', fn($q) => $q->whereIn('third_party_logistic_companies.id', (array) $v)))
            ->whereHas('captain', fn($query) => $query->whereIn('status', [Captain::STATUS_ACTIVE, Captain::STATUS_BANNED, Captain::STATUS_INACTIVE]))
            ->whereBetween('date', [$fromRaw, $toRaw])
            ->groupBy('captain_id');
 
        $commissionSubquery = DB::table('captain_commissions')
            ->select([
                'captain_id',
                DB::raw('SUM(balance) as total_balance'),
                DB::raw('AVG(acceptance_percent) as avg_acceptance'),
                DB::raw('MAX(commission_rule_id) as commission_rule_id'),
            ])
            ->where('commission_rule_type', 2)
            ->whereBetween('date', [$fromRaw, $toRaw])
            ->groupBy('captain_id');
 
        return DB::query()
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
                'logs.total_orders_received as received',
                'logs.total_orders_accepted as accepted',
                'logs.total_orders_try_to_accept as try_to_accept',
                'logs.total_orders_rejected as rejected',
                'logs.total_orders_expired as expired',
                'logs.total_orders_delivered as delivered',
                DB::raw('CASE 
                    WHEN logs.total_orders_received > 0 
                    THEN ROUND((logs.total_orders_accepted + logs.total_orders_try_to_accept) / logs.total_orders_received * 100, 2) 
                    ELSE 0 END as acceptance_rate'),
                DB::raw('COALESCE(cc.total_balance, 0) as sar_value'),
                'logs.total_orders_cancelled as cancelled',
                'logs.total_orders_returned as returned',
            ])
            ->groupBy(
                'logs.captain_id', 'u.name', 'tplc.name', 'cr.name',
                'logs.working_days', 'logs.productive_days', 'logs.total_seconds_worked',
                'logs.total_orders_received', 'logs.total_orders_accepted',
                'logs.total_orders_try_to_accept', 'logs.total_orders_rejected',
                'logs.total_orders_expired', 'logs.total_orders_delivered',
                'logs.total_orders_cancelled', 'logs.total_orders_returned',
                'cc.total_balance', 'cc.avg_acceptance'
            )
            ->when(($filters['sort_by'] ?? null) === 'acceptance_rate', function ($q) use ($filters) {
                $order = strtolower($filters['sort_order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
                $q->orderBy(DB::raw('CASE WHEN logs.total_orders_received > 0 THEN (logs.total_orders_accepted + logs.total_orders_try_to_accept) / logs.total_orders_received ELSE 0 END'), $order);
            })
            ->when(($filters['sort_by'] ?? null) === 'sar_value', function ($q) use ($filters) {
                $order = strtolower($filters['sort_order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
                $q->orderBy(DB::raw('cc.total_balance'), $order);
            })
            ->orderBy('logs.captain_id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getConsolidatedReports(array $filters, string $date): LengthAwarePaginator
    {
         $perPage = isset($filters['per_page']) && is_numeric($filters['per_page'])
            ? (int) $filters['per_page']
            : 20;
 
        $page = $filters['page'] ?? 1;
 
        $query = CommissionReport::query()
            ->whereDate('date', $date)
            ->when($filters['captain']         ?? null, fn($q, $v) => $q->where('captain_id', $v))
            ->when($filters['commission_type'] ?? null, function ($q, $v) {
                if ($v == 1) {
                    $q->where('commission_type', 'Delivery Based');
                } elseif ($v == 2) {
                    $q->where('commission_type', 'KPI Based');
                }
            })
            ->when($filters['code']       ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('code', $v)))
            ->when($filters['region_id']  ?? null, fn($q, $v) => $q->whereHas('captain.regions', fn($q) => $q->where('regions.id', $v)))
            ->when($filters['quadrant_id']?? null, fn($q, $v) => $q->whereHas('captain.regions.quadrant', fn($q) => $q->where('quadrants.id', $v)))
            ->when($filters['search']     ?? null, function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('employee_type', 'like', "%$search%")
                        ->orWhere('employer', 'like', "%$search%")
                        ->orWhereHas('captain', fn($q) => $q->where('code', 'like', "%$search%")
                            ->orWhereHas('user', fn($q) => $q->where('name', 'like', "%$search%")));
                });
            });
 
        $total = (clone $query)->count();
 
        $records = (clone $query)
            ->with([
                'captain.user',
                'captain.regions.quadrant',
                'captain.captainThirdParty.thirdPartCompany',
                'captain.workingLogs' => fn($q) => $q->whereDate('date', $date),
            ])
            ->forPage($page, $perPage)
            ->get();
 
        return new LengthAwarePaginator(
            $records,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function getLowPerformanceReports(Request $request): LengthAwarePaginator{
        $query = CaptainPerformanceReport::query()
            ->with([
                'captain.user',
                'captain.employmentType',
                'captain.region',
                'captain.region.quadrant',
            ]);

        $this->applyLowPerformanceFilters($query, $request);

        return $query
            ->whereDate('report_for', Carbon::today())
            ->orderBy('report_for', 'desc')
            ->paginate($request->get('per_page', 20));
    }

    private function applyLowPerformanceFilters($query, Request $request): void
    {
        if ($request->filled('captain')) {
            $query->whereIn('captain_id', $request->captain);
        }

        if ($request->filled('regions')) {
            $query->whereHas('captain.regions', function ($q) use ($request) {
                $q->whereIn('regions.id', $request->regions);
            });
        }

        if ($request->filled('areas_id')) {
            $query->whereHas('captain.regions.quadrant', function ($q) use ($request) {
                $q->whereIn('quadrants.id', $request->areas_id);
            });
        }

        if ($request->filled('job_type')) {
            $query->whereHas('captain.employmentType', function ($q) use ($request) {
                $q->where('id', $request->job_type);
            });
        }
    }

    

    public function getShiftReports(array $filters, string $date, int $perPage): LengthAwarePaginator
    {
        $query = CaptainShiftRuleReport::with([
            'captain.user:id,name',
            'captain.nationality:id,name',
            'captain.employmentType:id,name',
            'shiftRule:id,name',
        ])->whereDate('date', $date);

        $this->applyFilters($query, $filters);

        return $query->paginate($perPage);
    }

    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['captain_id'])) {
            $query->where('captain_id', $filters['captain_id']);
        }

        if (!empty($filters['shift_rule'])) {
            $query->where('shift_id', $filters['shift_rule']);
        }

        if (!empty($filters['job_type'])) {
            $query->whereHas('captain.employmentType', function ($q) use ($filters) {
                $q->where('captain_employment_type_id', $filters['job_type']);
            });
        }

        if (!empty($filters['nationality'])) {
            $query->whereHas('captain.nationality', function ($q) use ($filters) {
                $q->where('id', $filters['nationality']);
            });
        }

        if (!empty($filters['iqama'])) {
            $query->whereHas('captain.user', function ($q) use ($filters) {
                $q->where('iqama_number', 'like', "%{$filters['iqama']}%");
            });
        }

        if (!empty($filters['employee_id'])) {
            $query->whereHas('captain.user', function ($q) use ($filters) {
                $q->where('code', 'like', "%{$filters['employee_id']}%");
            });
        }
    }

}