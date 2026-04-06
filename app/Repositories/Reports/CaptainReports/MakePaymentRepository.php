<?php


namespace App\Repositories\Reports\CaptainReports;

use App\Captain;
use App\CaptainCommission;
use App\CaptainCommissionPayment;
use App\CaptainSalaryPayment;
use App\CaptainSalaryPaymentDate;
use App\Interfaces\Reports\CaptainReports\MakePaymentInterface;
use App\Order;
use App\OrderStatus;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MakePaymentRepository implements MakePaymentInterface
{
    public function getOrderStatistics(array $filters): object
    {
        $removeZero = $filters['removed_zero_captain'] ?? 1;

        return Order::query()
            ->select(
                DB::raw('COUNT(*) as attended_orders'),
                DB::raw('AVG(captain_commissions.commission) as total_avg_commission'),
                DB::raw('SUM(captain_commissions.commission) as total_commission'),
            )
            ->leftJoin('captain_commissions', 'captain_commissions.order_id', '=', 'orders.id')
            ->has('captainCommission')
            ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED])
            ->whereHas('captain', fn($q) => $q->allCommissionedCaptain())
            ->when($removeZero == 1, fn($q) => $q->where('captain_commissions.balance', '>', 0))
            ->when($filters['captain'] ?? null, fn($q, $v) => $q->where('orders.captain_id', $v))
            ->when($filters['captain_id'] ?? null, fn($q, $v) => $q->where('orders.captain_id', $v))
            ->when($filters['region'] ?? null, fn($q, $v) => $q->whereHas('captain.regions.quadrant', fn($q) => $q->where('quadrants.id', $v)))
            ->when($filters['job_type'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('captain_employment_type_id', $v)))
            ->when($filters['status'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('status', $v)))
            ->when($filters['on_duty_from'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('date_of_joining', '>=', now()->parse($v)->format('Y-m-d'))))
            ->when($filters['vehicle_type'] ?? null, fn($q, $v) => $q->whereHas('captain.vehicle.vehicleType', fn($q) => $q->where('id', $v)))
            ->when($filters['payment_status'] ?? null, function ($query, $status) {
                if ($status == 'Payable') {
                    $query->where('captain_commissions.balance', '>', 0);
                }
                if ($status == 'Tally') {
                    $query->where('captain_commissions.balance', '=', 0);
                }
            })
            ->when(
                isset($filters['from_date']) && isset($filters['to_date']),
                fn($q) => $q->whereBetween('orders.delivery_date', [
                    now()->parse($filters['from_date'])->format('Y-m-d') . ' 00:00:00',
                    now()->parse($filters['to_date'])->format('Y-m-d') . ' 23:59:59',
                ])
            )
            ->first();

    }

    public function getCaptainBalanceStatistics(array $filters): object
    {
        $removeZero = $filters['removed_zero_captain'] ?? 1;

        return Captain::query()
            ->allCommissionedCaptain()
            ->has('orders.captainCommission')
            ->leftJoin(
                DB::raw('(SELECT MAX(id) AS max_id, captain_id FROM captain_commissions GROUP BY captain_id) as max_commissions'),
                'captains.id',
                '=',
                'max_commissions.captain_id'
            )
            ->leftJoin('captain_commissions', 'max_commissions.max_id', '=', 'captain_commissions.id')
            ->select(DB::raw('COUNT(captains.id) as captains_count'))
            ->selectRaw('SUM(IFNULL(captain_commissions.balance, 0)) as total_payable_commission')
            ->when($filters['from_date'] ?? null, fn($q, $v) => $q->whereHas('orders', fn($q) => $q->where('orders.delivery_date', '>=', now()->parse($v)->format('Y-m-d') . ' 00:00:00')))
            ->when($filters['captain'] ?? null, fn($q, $v) => $q->where('captains.id', $v))
            ->when($filters['captain_id'] ?? null, fn($q, $v) => $q->where('captains.id', $v))
            ->when($filters['region'] ?? null, fn($q, $v) => $q->whereHas('regions.quadrant', fn($q) => $q->where('quadrants.id', $v)))
            ->when($filters['job_type'] ?? null, fn($q, $v) => $q->where('captains.captain_employment_type_id', $v))
            ->when($filters['status'] ?? null, fn($q, $v) => $q->where('captains.status', '=', $v))
            ->when($filters['payment_status'] ?? null, function ($query, $status) {
                if ($status == 'Payable') {
                    $query->where('balance', '>', 0);
                }
                if ($status == 'Tally') {
                    $query->where('balance', '=', 0);
                }
            })
            ->when($filters['vehicle_type'] ?? null, fn($q, $v) => $q->whereHas('vehicle.vehicleType', fn($q) => $q->where('id', $v)))
            ->when($removeZero == 1, fn($q) => $q->where('captain_commissions.balance', '>', 0))
            ->first();
    }
    public function getCaptainPayments(array $filters, int $perPage = 20): LengthAwarePaginator
    {


        $from_date = $filters['from_date'] ?? null;
        $to_date = $filters['to_date'] ?? null;
        $removed_zero_captain = $filters['removed_zero_captain'] ?? 1;

        $captains = Captain::query()
            ->select([
                'captains.id',
                'captains.code',
                'captains.status',
                'captains.captain_employment_type_id',
                'captains.date_of_joining',
                'captains.monthly_salary',
                'captains.user_id',
            ])

            ->with([
                'user:id,name',
                'employmentType:id,name',
                'vehicle:id,type,assigned_to,name,number,brand',
                'vehicle.vehicleType:id,name',
                'regions:id,quadrant_id',
                'regions.quadrant:id,name',
            ])

            ->withCommissionBalance()

            ->whereDoesntHave('captainThirdParty')

            ->leftJoin(
                DB::raw('(SELECT MAX(id) AS max_id, captain_id FROM captain_commissions GROUP BY captain_id) as max_commissions'),
                'captains.id',
                '=',
                'max_commissions.captain_id'
            )
            ->leftJoin('captain_commissions', 'max_commissions.max_id', '=', 'captain_commissions.id')

            ->withCount([
                'orders as attended_orders' => function ($query) use ($from_date, $to_date) {
                    $query->has('captainCommission')
                        ->when(
                            $from_date,
                            fn($q) =>
                            $q->where('orders.delivery_date', '>=', now()->parse($from_date)->startOfDay())
                        )
                        ->when(
                            $to_date,
                            fn($q) =>
                            $q->where('orders.delivery_date', '<=', now()->parse($to_date)->endOfDay())
                        )
                        ->whereIn('orders.status_id', [
                            OrderStatus::DELIVERED,
                            OrderStatus::CLIENT_RETURN_ACCEPTED
                        ]);
                }
            ])

            ->withAvg([
                'commissions as avg_commission' => function ($query) use ($from_date, $to_date) {
                    $query->when(
                        $from_date && $to_date,
                        fn($q) =>
                        $q->whereBetween('captain_commissions.created_at', [
                            now()->parse($from_date)->startOfDay(),
                            now()->parse($to_date)->endOfDay(),
                        ])
                    );
                }
            ], 'commission')

            ->withSum([
                'commissions as total_additional_km_earning' => function ($query) use ($from_date, $to_date) {
                    $query->when(
                        $from_date && $to_date,
                        fn($q) =>
                        $q->whereBetween('captain_commissions.created_at', [
                            now()->parse($from_date)->startOfDay(),
                            now()->parse($to_date)->endOfDay(),
                        ])
                    );
                }
            ], 'additional_km_earning')

            ->withSum([
                'commissions as total_filter_commission' => function ($query) use ($from_date, $to_date) {
                    $query->join('orders', 'orders.id', '=', 'captain_commissions.order_id')
                        ->when(
                            $from_date && $to_date,
                            fn($q) =>
                            $q->whereBetween('orders.delivery_date', [
                                now()->parse($from_date)->startOfDay(),
                                now()->parse($to_date)->endOfDay(),
                            ])
                        );
                }
            ], 'commission');

        // ✅ Apply date filters (important)
        if ($from_date || $to_date) {
            $captains
                ->when(
                    $from_date,
                    fn($q, $v) =>
                    $q->whereHas(
                        'orders',
                        fn($q) =>
                        $q->where('delivery_date', '>=', now()->parse($v)->startOfDay())
                    )
                )
                ->when(
                    $to_date,
                    fn($q, $v) =>
                    $q->whereHas(
                        'orders',
                        fn($q) =>
                        $q->where('delivery_date', '<=', now()->parse($v)->endOfDay())
                    )
                )
                ->when(
                    $from_date,
                    fn($q, $v) =>
                    $q->whereHas(
                        'commissions',
                        fn($q) =>
                        $q->where('created_at', '>=', now()->parse($v)->startOfDay())
                    )
                )
                ->when(
                    $to_date,
                    fn($q, $v) =>
                    $q->whereHas(
                        'commissions',
                        fn($q) =>
                        $q->where('created_at', '<=', now()->parse($v)->endOfDay())
                    )
                );
        }

        // ✅ Apply filters
        return $captains
            ->when(
                $filters['captain'] ?? null,
                fn($q, $v) =>
                $q->where('captains.id', $v)
            )
            ->when(
                $filters['captain_id'] ?? null,
                fn($q, $v) =>
                $q->where('captains.id', $v)
            )
            ->when(
                $filters['region'] ?? null,
                fn($q, $v) =>
                $q->whereHas(
                    'regions.quadrant',
                    fn($q) =>
                    $q->where('quadrants.id', $v)
                )
            )
            ->when(
                $filters['vehicle_type'] ?? null,
                fn($q, $v) =>
                $q->whereHas(
                    'vehicle.vehicleType',
                    fn($q) =>
                    $q->where('id', $v)
                )
            )
            ->when(
                $filters['status'] ?? null,
                fn($q, $v) =>
                $q->where('captains.status', $v)
            )
            ->when(
                !isset($filters['status']),
                fn($q) =>
                $q->where('captains.status', 'active')
            )
            ->when(
                $filters['job_type'] ?? null,
                fn($q, $v) =>
                $q->where('captain_employment_type_id', $v)
            )
            ->when($filters['payment_status'] ?? null, function ($query, $status) {
                if ($status === 'Payable') {
                    $query->where('captain_commissions.balance', '>', 0);
                }
                if ($status === 'Tally') {
                    $query->where('captain_commissions.balance', '=', 0);
                }
            })
            ->when(
                $removed_zero_captain == 1,
                fn($q) =>
                $q->where('captain_commissions.balance', '>', 0)
            )
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getCaptainsSalaryDetails(array $captainIds, array $filters): Collection
    {
        $from_date = $filters['from_date'] ?? null;
        $to_date = $filters['to_date'] ?? null;

        return Captain::query()
            ->with('user', 'employmentType', 'regions', 'regions.quadrant')
            ->withCommissionBalance()
            ->whereDoesntHave('captainThirdParty')
            ->withCount([
                'orders as attended_orders' => function ($query) use ($from_date, $to_date) {
                    $query->has('captainCommission')
                        ->when($from_date, fn($q, $v) => $q->where('orders.delivery_date', '>=', now()->parse($v)->format('Y-m-d') . ' 00:00:00'))
                        ->when($to_date, fn($q, $v) => $q->where('orders.delivery_date', '<=', now()->parse($v)->format('Y-m-d') . ' 23:59:59'))
                        ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED]);
                }
            ])
            ->withAvg([
                'commissions as avg_commission' => function ($query) use ($from_date, $to_date) {
                    $query->when($from_date && $to_date, fn($q) => $q->whereBetween('captain_commissions.created_at', [
                        now()->parse($from_date)->format('Y-m-d') . ' 00:00:00',
                        now()->parse($to_date)->format('Y-m-d') . ' 23:59:59',
                    ]));
                }
            ], 'commission')
            ->withSum([
                'commissions as total_commission' => function ($query) use ($from_date, $to_date) {
                }
            ], 'commission')
            ->withSum([
                'commissions as total_additional_km_earning' => function ($query) use ($from_date, $to_date) {
                    $query->when($from_date && $to_date, fn($q) => $q->whereBetween('captain_commissions.created_at', [
                        now()->parse($from_date)->format('Y-m-d') . ' 00:00:00',
                        now()->parse($to_date)->format('Y-m-d') . ' 23:59:59',
                    ]));
                }
            ], 'additional_km_earning')
            ->withSum([
                'commissions as total_filter_commission' => function ($query) use ($from_date, $to_date) {
                    $query->join('orders', 'orders.id', '=', 'captain_commissions.order_id')
                        ->when($from_date && $to_date, fn($q) => $q->whereBetween('orders.delivery_date', [
                            now()->parse($from_date)->format('Y-m-d') . ' 00:00:00',
                            now()->parse($to_date)->format('Y-m-d') . ' 23:59:59',
                        ]));
                }
            ], 'commission')
            ->whereIn('id', $captainIds)
            ->get();
    }

    public function getLatestCommission(int $captainId): ?CaptainCommission
    {
        return CaptainCommission::where('captain_id', $captainId)->latest('id')->first();
    }
 
    public function settleCommission(CaptainCommission $commission, array $data): CaptainCommission
    {
        $commission->settled_amount  = $commission->settled_amount + abs($data['amount_paid']);
        $commission->payment_mode_id = $data['payment_mode'];
        $commission->balance         = $commission->balance - $data['amount_paid'];
        $commission->settled_by      = $data['settled_by'];
        $commission->settled_at      = $data['settled_at'];
        $commission->save();
 
        return $commission;
    }
 
    public function insertCommissionPayments(array $payments): void
    {
        CaptainCommissionPayment::insert($payments);
    }
 
    public function createSalaryPayment(array $data): object
    {
        return CaptainSalaryPayment::create($data);
    }
 
    public function insertSalaryPaymentDates(array $dates): void
    {
        if (!empty($dates)) {
            CaptainSalaryPaymentDate::insert($dates);
        }
    }
 
    public function salaryPaymentDateExists(int $captainId, string $date): bool
    {
        return CaptainSalaryPaymentDate::where('captain_id', $captainId)
            ->where('paid_on_date', $date)
            ->exists();
    }

}