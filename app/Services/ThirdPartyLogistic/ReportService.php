<?php

namespace App\Services\ThirdPartyLogistic;

use App\Interfaces\ThirdPartyLogisticReportInterface;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use App\OrderStatus;
use Illuminate\Pagination\LengthAwarePaginator;

class ReportService
{
    public function __construct(private readonly ThirdPartyLogisticReportInterface $reportRepository) {}

    public function getOrderDataFor3PL($request)
    {
        $companyId = $request->get('company_id_3pl');
        $perPage = $request->get('per_page', 20);

        $filters = $request->only(['status_id', 'delivery_type', 'from_date', 'to_date', 'captain', 'client_id', 'search', 'clients', 'status', 'zone', 'region', 'shopname', 'time_slot', 'orderID', 'order_type']);

        $statusGroups = [
            'on_going_orders_count' => [OrderStatus::ACCEPT, OrderStatus::START_RIDE, OrderStatus::REACHED_SHOP, OrderStatus::PICKED, OrderStatus::PICKED_UP, OrderStatus::SHIPPED, OrderStatus::REACHED_DESTINATION, OrderStatus::REROUTED],

            'complaints_orders_count' => [OrderStatus::TICKET_RAISED, OrderStatus::PENDING],

            'client_return_orders_count' => [OrderStatus::RETURN_TO_CLIENT],

            'request_for_cancel_orders_count' => [OrderStatus::REQUEST_FOR_CANCEL],
        ];

        $counts = [];
        foreach ($statusGroups as $key => $statuses) {
            $counts[$key] = $this->reportRepository->getOrderCounts(
                $companyId,
                array_merge($filters, [
                    'statuses' => $statuses,
                ]),
            );
        }

        if (!empty($filters['from_date']) && !empty($filters['to_date'])) {
            [$start, $end] = $this->getBusinessDayRange($filters['from_date'], $filters['to_date']);

            $filters['from_date'] = $start;
            $filters['to_date'] = $end;
        }

        $query = $this->reportRepository->getListOrderQuery($companyId);
        $query = $this->reportRepository->applyOrderFilters($query, $filters);


        return  tap($query->paginate($perPage), function ($paginator) {
            $paginator->withQueryString();
        });
    }
    private function getBusinessDayRange(string $fromDate, string $toDate): array
    {
        $start = Carbon::parse($fromDate)->startOfDay()->addHours(6);

        $end = Carbon::parse($toDate)->startOfDay()->addHours(30)->subSecond();

        return [$start, $end];
    }

    public function getCaptainListFor3PL($request)
    {
        $companyId = $request->get('company_id_3pl');
        $perPage = $request->get('per_page', 20);

        $filters = $request->only(['name', 'mobile_no', 'vehicle_no', 'shift_status', 'nationality', 'vehicle_type', 'captain', 'status', 'job_type', 'quadrant_id', 'sort_by', 'sort_order', 'region_id']);

        return $this->reportRepository->getCaptainList($companyId, $filters, $perPage);
    }

    public function getVehicleList($request)
    {
        $companyId = $request->get('company_id_3pl');
        $perPage   = $request->get('per_page', 20);
        $filters = $request->only([
            'region',
            'vehicle_type',
            'vehicle_no',
            'captain',
            'status',
            'employment_type',
            'owner',
        ]);
        return $this->reportRepository->getVehicleList($companyId, $filters, $perPage);
    }

    public function getVehicleCount($request)
    {
        $companyId = $request->get('company_id_3pl');
        return $this->reportRepository->getVehicleCount($companyId);
    }

    public function getCommissionList($request)
    {
        $companyId = $request->get('company_id_3pl');
        $perPage = $request->get('per_page', 20);

        $filters = $request->only(['employee_id', 'captain', 'name', 'iqama', 'region', 'area', 'job_type', 'nationality', 'on_duty_from', 'work_status', 'payment_status']);

        // Get paginated list
        $captains = $this->reportRepository->getCommissionList($companyId, $filters, $perPage);
        $collection = $captains->getCollection();
        $totalAttended = $collection->sum('attended_orders');
        $totalCommission = $collection->sum('total_commission');

        $overallAvgPerOrder = $totalAttended > 0 ? $totalCommission / $totalAttended : 0;

        $counts = [
            'total_attended_orders' => $captains->getCollection()->sum('attended_orders'),
            'total_commission' => number_format($captains->getCollection()->sum('total_commission'), 2),
            'average_commission' => number_format($overallAvgPerOrder, 2),
            'total_paid_commission' => number_format($captains->getCollection()->sum('paid_commission'), 2),
            'total_payable' => number_format($captains->getCollection()->sum(fn($c) => $c->lastCommission->balance ?? 0), 2),
        ];

        return [
            'captains' => $captains,
            'counts' => $counts,
        ];
    }

    public function getCommissionCounts($request)
    {
        $companyId = $request->get('company_id_3pl');

        $filters = $request->only(['employee_id', 'captain', 'name', 'iqama', 'region', 'area', 'job_type', 'nationality', 'on_duty_from', 'work_status', 'payment_status']);

        // Get full collection WITHOUT pagination
        $captains = $this->reportRepository->getCommissionCounts($companyId, $filters);

        $totalAttended = $captains->sum('attended_orders');
        $totalCommission = $captains->sum('total_commission');
        $totalPaid = $captains->sum('paid_commission');
        $totalPayable = $captains->sum(fn($c) => $c->lastCommission->balance ?? 0);

        // Correct overall avg commission per order
        $overallAvgPerOrder = $totalAttended > 0 ? $totalCommission / $totalAttended : 0;

        return [
            'total_attended_orders' => $totalAttended,
            'total_commission' => number_format($totalCommission, 2),
            'average_commission' => number_format($overallAvgPerOrder, 2),
            'total_paid_commission' => number_format($totalPaid, 2),
            'total_payable' => number_format($totalPayable, 2),
        ];
    }
    public function getDetailsList(int $captainId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->reportRepository->getCaptainCommissionList($captainId, $filters, $perPage);
    }

    public function getDetailsCount(int $companyId, array $filters, int $captainId)
    {
        $stats = $this->reportRepository
            ->getCaptainCommissionStatisticsQuery($companyId, $filters, $captainId)
            ->selectRaw(
                "
                COUNT(*) as attended_orders,
                AVG(captain_commissions.commission) as total_avg_commission,
                SUM(captain_commissions.commission) as total_commission,
                SUM(captain_commissions.settled_amount) as total_payed_amount
            ",
            )
            ->first();
        $balance = $this->reportRepository->getTotalPayableCommissionQuery($companyId, $filters, $captainId)->first();
        return [
            'attended_orders' => $stats->attended_orders,
            'total_avg_commission' => number_format($stats->total_avg_commission, 2),
            'total_commission' => number_format($stats->total_commission, 2),
            'total_payed_amount' => number_format($stats->total_payed_amount, 2),
            'total_payable_commission' => number_format($balance->total_payable_commission ?? 0, 2),
        ];
    }

    public function getTotalPayableCommission(int $companyId, array $filters, int $captainId)
    {
        return $this->reportRepository->getTotalPayableCommissionQuery($companyId, $filters, $captainId)->first();
    }

    public function getCaptainTransactionList(int $companyId, array $filters, int $perPage = 20)
    {
        return $this->reportRepository->getCaptainTransaction($companyId, $filters, $perPage);
    }

    public function generateCaptainPerformanceReport(int $companyId, array $filters, int $perPage = 20)
    {
        $filters['captain']           = $this->toArray($filters['captain'] ?? null);
        $filters['areas_id']          = $this->toArray($filters['areas_id'] ?? null);
        $filters['employment_type']   = $this->toArray($filters['employment_type'] ?? null);
        $filters['regions']           = $this->toArray($filters['regions'] ?? null);
        return $this->reportRepository->getCaptainsPerformanceReport(companyId: $companyId, filters: $filters, perPage: $perPage);
    }
    private function toArray($value)
    {
        if (is_array($value)) return $value;
        if (is_string($value)) return array_filter(explode(',', $value));
        return [];
    }

    public function getWorkingDaysReport($filters, $companyId, $perPage)
    {
        $from = Carbon::parse($filters['from_date'] ?? now()->startOfMonth());
        $to   = Carbon::parse($filters['to_date'] ?? now()->subDay());
        $period = CarbonPeriod::create($from, $to);
        $captains = $this->reportRepository->getDaysFilteredCaptainsReports($filters, $companyId, $perPage);
        $workingDays = $this->reportRepository->getCaptainWorkingDaysData($from, $to, $captains->pluck('id')->toArray());
        $reports = $this->formatCaptainDaysReports($captains, $period, $workingDays);
        return [
            'period' => collect($period)->map(fn($d) => $d->format('d-M'))->toArray(),
            'captains' => $reports,
            'pagination' => [
                'current_page' => $captains->currentPage(),
                'total' => $captains->total(),
                'per_page' => $captains->perPage(),
                'last_page' => $captains->lastPage(),
            ]
        ];
    }
    private function formatCaptainDaysReports($captains, $period, $workingDays)
    {

        $reports = [];
        $periodDates = collect($period)->map(fn($d) => $d->format('Y-m-d'))->toArray();

        foreach ($captains as $captain) {
            $days = $workingDays->where('captain_id', $captain->id);
            $reportDays = [];

            foreach ($periodDates as $date) {
                $work = $days->firstWhere('date', $date);

                $reportDays[] = [
                    'date' => $date,
                    'working_hr' => $work ? gmdate('H:i', $work->working_seconds) : 'Nil',
                    'completed_orders' => $work ? (int)$work->completed_orders : 'Nil',
                ];
            }

            $reports[] = [
                'captain' => $captain,
                'days' => $reportDays,
            ];
        }
        return $reports;
    }

    public function getCaptainCommissionPaymentDetailReport(int $companyId, array $filters, int $perPage = 20)
    {
        return $this->reportRepository->captainCommissionPaymentDetails($companyId, $filters, $perPage);
    }

    public function getCaptainCommissionConfirmPaymentReport(int $companyId, array $filters, int $perPage = 20)
    {
        $captain  = $this->reportRepository->getCaptainCommissionConfirmPaymentReport($companyId, $filters, $perPage);
        $summary  = $this->reportRepository->getCaptainCommissionConfirmCountSummary($companyId, $filters);

        return [
            'captains' => $captain,
            'summary'  => $summary,
        ];
    }
}
