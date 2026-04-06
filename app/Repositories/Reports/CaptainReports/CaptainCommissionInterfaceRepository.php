<?php

namespace App\Repositories\Reports\CaptainReports;

use App\Captain;
use App\CaptainBonus;
use App\CaptainCommission;
use App\CaptainCommissionPayment;
use App\CaptainReport;
use App\Interfaces\Reports\CaptainReports\CaptainCommissionInterface;
use App\Order;
use App\OrderStatus;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CaptainCommissionInterfaceRepository implements CaptainCommissionInterface
{
    public function getFilteredCaptains(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return Captain::query()
            ->with('user', 'employmentType', 'nationality', 'regions.quadrant')
            ->leftJoin(DB::raw('(SELECT MAX(id) AS max_id, captain_id FROM captain_commissions GROUP BY captain_id) as max_commissions'), 'captains.id', '=', 'max_commissions.captain_id')
            ->leftJoin('captain_commissions', 'max_commissions.max_id', '=', 'captain_commissions.id')
            ->allCommissionedCaptain()
            ->withCount([
                'orders as attended_orders' => function ($query) {
                    $query->has('captainCommission')->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED]);
                },
            ])
            ->withAvg(['commissions as avg_commission'], 'commission')
            ->withSum(['commissions as total_commission'], 'commission')
            ->withSum(['commissions as paid_commission'], 'settled_amount')
            ->has('orders.captainCommission')
            ->when($filters['employee_id'] ?? null, fn($q, $v) => $q->whereLike('code', $v))
            ->when($filters['captain'] ?? null, fn($q, $v) => $q->where('captains.id', $v))
            ->when($filters['name'] ?? null, fn($q, $v) => $q->whereLike(['user.name'], $v))
            ->when($filters['iqama'] ?? null, fn($q, $v) => $q->where('captains.iqama_number', 'LIKE', $v . '%'))
            ->when($filters['region'] ?? null, fn($q, $v) => $q->whereHas('regions.quadrant', fn($q) => $q->where('quadrants.id', $v)))
            ->when($filters['area'] ?? null, fn($q, $v) => $q->whereHas('regions', fn($q) => $q->where('regions.id', $v)))
            ->when($filters['job_type'] ?? null, fn($q, $v) => $q->where('captains.captain_employment_type_id', $v))
            ->when($filters['nationality'] ?? null, fn($q, $v) => $q->where('captains.nationality_id', $v))
            ->when($filters['on_duty_from'] ?? null, fn($q, $v) => $q->where('captains.date_of_joining', '>=', now()->parse($v)->format('Y-m-d')))
            ->when($filters['work_status'] ?? null, fn($q, $v) => $q->where('captains.status', $v))
            ->when($filters['third_party_logistic_company'] ?? null, fn($q, $v) => $q->whereHas('company', fn($q) => $q->where('third_party_logistic_companies.id', $v)))
            ->when($filters['payment_status'] ?? null, function ($query, $status) {
                match ($status) {
                    'Payable' => $query->where('balance', '>', 0),
                    'Tally' => $query->where('balance', '=', 0),
                    default => null,
                };
            })
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getOrderStatistics(array $filters): object
    {
        return Order::query()
            ->select(
                DB::raw('COUNT(*) as attended_orders'),
                DB::raw('AVG(captain_commissions.commission) as total_avg_commission'),
                DB::raw('SUM(captain_commissions.commission) as total_commission'),
                DB::raw('SUM(captain_commissions.settled_amount) as total_payed_amount'),
                DB::raw('SUM(cm.balance) as balance')
            )
            ->leftJoin('captain_commissions', 'captain_commissions.order_id', '=', 'orders.id')
            ->leftJoin('captains', 'captains.id', '=', 'orders.captain_id')
            ->leftJoin(
                DB::raw('(SELECT MAX(id) AS max_id, captain_id FROM captain_commissions GROUP BY captain_id) as max_commissions'),
                'captains.id',
                '=',
                'max_commissions.captain_id'
            )
            ->leftJoin('captain_commissions as cm', 'max_commissions.max_id', '=', 'cm.id')
            ->has('captainCommission')
            ->has('captain.captainThirdParty')
            ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED])
            ->when($filters['captain'] ?? null, fn($q, $v) => $q->where('orders.captain_id', $v))
            ->when($filters['employee_id'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('code', 'LIKE', $v . '%')))
            ->when($filters['third_party_company_id'] ?? null, fn($q, $v) => $q->whereHas('captainThirdParty', fn($q) => $q->where('third_party_logistic_company_id', $v)))
            ->when($filters['name'] ?? null, fn($q, $v) => $q->whereHas('captain.user', fn($q) => $q->where('name', 'LIKE', $v . '%')))
            ->when($filters['iqama'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('iqama_number', 'LIKE', $v . '%')))
            ->when($filters['region'] ?? null, fn($q, $v) => $q->whereHas('captain.regions.quadrant', fn($q) => $q->where('quadrants.id', $v)))
            ->when($filters['area'] ?? null, fn($q, $v) => $q->whereHas('captain.regions', fn($q) => $q->where('regions.id', $v)))
            ->when($filters['job_type'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('captain_employment_type_id', $v)))
            ->when($filters['nationality'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('nationality_id', $v)))
            ->when($filters['on_duty_from'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('date_of_joining', '>=', now()->parse($v)->format('Y-m-d'))))
            ->when($filters['work_status'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('status', $v)))
            ->when($filters['payment_status'] ?? null, function ($query, $status) {
                match ($status) {
                    'Payable' => $query->where('cm.balance', '>', 0),
                    'Tally' => $query->where('cm.balance', '=', 0),
                    default => null,
                };
            })
            ->first();
    }

    public function getCaptainBalanceStatistics(array $filters): object
    {
        return Captain::query()
            ->has('captainThirdParty')
            ->has('orders.captainCommission')
            ->leftJoin(
                DB::raw('(SELECT MAX(id) AS max_id, captain_id FROM captain_commissions GROUP BY captain_id) as max_commissions'),
                'captains.id',
                '=',
                'max_commissions.captain_id'
            )
            ->leftJoin('captain_commissions', 'max_commissions.max_id', '=', 'captain_commissions.id')
            ->selectRaw('SUM(IFNULL(captain_commissions.balance, 0)) as total_payable_commission')
            ->when($filters['employee_id'] ?? null, fn($q, $v) => $q->whereLike('code', $v))
            ->when($filters['captain'] ?? null, fn($q, $v) => $q->where('captains.id', $v))
            ->when($filters['name'] ?? null, fn($q, $v) => $q->whereLike(['user.name'], $v))
            ->when($filters['iqama'] ?? null, fn($q, $v) => $q->where('captains.iqama_number', 'LIKE', $v . '%'))
            ->when($filters['region'] ?? null, fn($q, $v) => $q->whereHas('regions.quadrant', fn($q) => $q->where('quadrants.id', $v)))
            ->when($filters['area'] ?? null, fn($q, $v) => $q->whereHas('regions', fn($q) => $q->where('regions.id', $v)))
            ->when($filters['job_type'] ?? null, fn($q, $v) => $q->where('captains.captain_employment_type_id', $v))
            ->when($filters['nationality'] ?? null, fn($q, $v) => $q->where('captains.nationality_id', $v))
            ->when($filters['on_duty_from'] ?? null, fn($q, $v) => $q->where('captains.date_of_joining', '>=', now()->parse($v)->format('Y-m-d')))
            ->when($filters['work_status'] ?? null, fn($q, $v) => $q->where('captains.status', $v))
            ->when($filters['payment_status'] ?? null, function ($query, $status) {
                match ($status) {
                    'Payable' => $query->where('balance', '>', 0),
                    'Tally' => $query->where('balance', '=', 0),
                    default => null,
                };
            })
            ->first();
    }

    public function getCommissionOrders(int $captainId, array $filters, array $dateRange, int $perPage = 20): LengthAwarePaginator
    {
        [$from, $to] = $dateRange;

        return Order::select('orders.*')
            ->with([
                'captain.user',
                'client.user',
                'shop',
                'progress',
                'payment',
                'shopPayment',
                'captainCommission.settledBy',
                'captainCommission.attachments',
            ])
            ->leftJoin('captain_commissions', 'captain_commissions.order_id', '=', 'orders.id')
            ->where('orders.captain_id', $captainId)
            ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED])
            ->has('captainCommission')
            ->whereBetween('orders.delivery_date', [$from, $to])
            ->orderBy('captain_commissions.id', 'desc')
            ->when($filters['region'] ?? null, fn($q, $v) => $q->where(fn($q) => $q->where('region_id', $v)->orWhereHas('shop.region', fn($q) => $q->where('id', $v))))
            ->when($filters['q'] ?? null, fn($q, $v) => $q->where('orders.client_order_id', 'LIKE', $v . '%'))
            ->when($filters['status'] ?? null, fn($q, $v) => $q->where('orders.status_id', $v))
            ->when($filters['client'] ?? null, fn($q, $v) => $q->where('orders.client_id', $v))
            ->when($filters['shop'] ?? null, fn($q, $v) => $q->where('orders.shopname', $v))
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getCommissionOrderStatistics(int $captainId, array $filters, array $dateRange): object
    {
        [$from, $to] = $dateRange;

        return Order::query()
            ->select(
                DB::raw('COUNT(*) as attended_orders'),
                DB::raw('AVG(captain_commissions.commission) as avg_commission'),
                DB::raw('SUM(captain_commissions.commission) as total_commission'),
                DB::raw('SUM(captain_commissions.settled_amount) as total_payed_commission'),
            )
            ->leftJoin('captain_commissions', 'captain_commissions.order_id', '=', 'orders.id')
            ->where('orders.captain_id', $captainId)
            ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED])
            ->has('captainCommission')
            ->whereBetween('orders.delivery_date', [$from, $to])
            ->when($filters['region'] ?? null, fn($q, $v) => $q->where(fn($q) => $q->where('region_id', $v)->orWhereHas('shop.region', fn($q) => $q->where('id', $v))))
            ->when($filters['q'] ?? null, fn($q, $v) => $q->where('orders.client_order_id', 'LIKE', $v . '%'))
            ->when($filters['status'] ?? null, fn($q, $v) => $q->where('orders.status_id', $v))
            ->when($filters['client'] ?? null, fn($q, $v) => $q->where('orders.client_id', $v))
            ->when($filters['shop'] ?? null, fn($q, $v) => $q->where('orders.shopname', $v))
            ->first();
    }

    public function getCommissionSummary(int $captainId, array $dateRange): object
    {
        [$from, $to] = $dateRange;

        return Captain::query()
            ->with('lastCommission')
            ->withSum([
                'commissions as total_commission' => fn($q) => $q
                    ->join('orders', 'orders.id', '=', 'captain_commissions.order_id')
                    ->whereBetween('orders.delivery_date', [$from, $to]),
            ], 'commission')
            ->withSum([
                'commissions as total_filter_commission' => fn($q) => $q
                    ->join('orders', 'orders.id', '=', 'captain_commissions.order_id')
                    ->whereBetween('orders.delivery_date', [$from, $to]),
            ], 'commission')
            ->where('id', $captainId)
            ->first();
    }

    public function getTotalBonus(int $captainId, array $dateRange): float
    {
        [$from, $to] = $dateRange;

        return CaptainBonus::where('captain_id', $captainId)
            ->whereBetween('bonus_date', [$from->toDateString(), $to->toDateString()])
            ->sum('amount');
    }

    public function getBonusRecords(int $captainId, array $dateRange): Collection
    {
        [$from, $to] = $dateRange;

        return CaptainBonus::where('captain_id', $captainId)
            ->whereBetween('bonus_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('bonus_date', 'desc')
            ->get();
    }

    public function getPreviousEditableCommission(int $captainId): ?object
    {
        $hasMultiple = CaptainCommission::where('captain_id', $captainId)
            ->whereNotNull('settled_by')
            ->count() > 1;

        if (!$hasMultiple) {
            return null;
        }

        return CaptainCommission::with('commissionPaymentLatest')
            ->where('captain_id', $captainId)
            ->whereNotNull('settled_by')
            ->latest('id')
            ->first();
    }

    public function getLatestCommission(int $captainId): ?CaptainCommission
    {
        return CaptainCommission::where('captain_id', $captainId)->latest('id')->first();
    }

    public function settleCommission(CaptainCommission $commission, array $data): CaptainCommission
    {
        $commission->settled_amount = $commission->settled_amount + $data['transferred'];
        $commission->payment_mode_id = $data['payment_mode'];
        $commission->reference_no = $data['reference_no'];
        $commission->balance = $commission->balance - $data['transferred'];
        $commission->settled_by = $data['settled_by'];
        $commission->settled_at = now();
        $commission->save();

        return $commission;
    }

    public function createPaymentRecord(array $data): void
    {
        CaptainCommissionPayment::create($data);
    }

    public function storeAttachments(CaptainCommission $commission, array $attachments): void
    {
        if (empty($attachments)) {
            return;
        }

        $commission->attachments()->createMany(
            array_map(fn($attachment) => [
                'path' => str_replace(
                    'public',
                    'storage',
                    $attachment->storePublicly('public/captain_commission_settlement_attachment')
                ),
            ], $attachments)
        );
    }

    public function save(CaptainCommission $commission): bool
    {
        return $commission->save();
    }

    public function getCommissionReportV2(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return CaptainReport::query()
            ->select([
                'id',
                'captain_id',
                'commissioned_attended_orders',
                'total_commission',
                'paid_commission',
                'balance_commission',
                'date_of_joining'
            ])
            ->with([
                'captain:id,code,user_id,captain_employment_type_id,iqama_number,nationality_id,status,region_id',

                'captain.user:id,name',

                'captain.employmentType:id,name',

                'captain.nationality:id,name',

                'captain.regions:id,name,quadrant_id',
                'captain.regions.quadrant:id,name',
            ])
            ->whereHas('captain.commissions')

            ->when(
                $filters['employee_id'] ?? null,
                fn($q, $v) =>
                $q->whereHas('captain', fn($q) => $q->where('code', 'LIKE', "%$v%"))
            )

            ->when(
                $filters['captain'] ?? null,
                fn($q, $v) =>
                $q->where('captain_id', $v)
            )

            ->when(
                $filters['name'] ?? null,
                fn($q, $v) =>
                $q->whereHas('captain.user', fn($q) => $q->where('name', 'LIKE', "%$v%"))
            )

            ->when(
                $filters['iqama'] ?? null,
                fn($q, $v) =>
                $q->whereHas('captain', fn($q) => $q->where('iqama_number', 'LIKE', "$v%"))
            )

            ->when(
                $filters['region'] ?? null,
                fn($q, $v) =>
                $q->whereHas('captain.regions.quadrant', fn($q) => $q->where('quadrants.id', $v))
            )

            ->when(
                $filters['area'] ?? null,
                fn($q, $v) =>
                $q->whereHas('captain.regions', fn($q) => $q->where('regions.id', $v))
            )

            ->when(
                $filters['job_type'] ?? null,
                fn($q, $v) =>
                $q->whereHas('captain', fn($q) => $q->where('captain_employment_type_id', $v))
            )

            ->when(
                $filters['nationality'] ?? null,
                fn($q, $v) =>
                $q->whereHas('captain', fn($q) => $q->where('nationality_id', $v))
            )

            ->when(
                $filters['on_duty_from'] ?? null,
                fn($q, $v) =>
                $q->whereHas(
                    'captain',
                    fn($q) =>
                    $q->where('date_of_joining', '>=', now()->parse($v)->format('Y-m-d'))
                )
            )

            ->when(
                $filters['work_status'] ?? null,
                fn($q, $v) =>
                $q->whereHas('captain', fn($q) => $q->where('status', $v))
            )

            ->when(
                $filters['third_party_logistic_company'] ?? null,
                fn($q, $v) =>
                $q->whereHas('captain.company', fn($q) => $q->where('id', $v))
            )

            ->when($filters['payment_status'] ?? null, function ($query, $status) {
                match ($status) {
                    'Payable' => $query->where('balance_commission', '>', 0),
                    'Tally' => $query->where('balance_commission', '=', 0),
                    default => null,
                };
            })

            ->paginate($perPage)
            ->withQueryString();
    }

    public function getStatisticsV2(array $filters): object
    {
        return CaptainReport::query()
            ->whereHas('captain.commissions')
            ->select(
                DB::raw('SUM(commissioned_attended_orders) AS attended_orders'),
                DB::raw('SUM(total_commission)             AS total_commission'),
                DB::raw('SUM(paid_commission)              AS total_payed_amount'),
                DB::raw('SUM(balance_commission)           AS balance'),
            )
            ->when($filters['employee_id'] ?? null, fn($q, $v) => $q->whereLike('captain.code', $v))
            ->when($filters['captain'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('captains.id', $v)))
            ->when($filters['name'] ?? null, fn($q, $v) => $q->whereLike(['captain.user.name'], $v))
            ->when($filters['iqama'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('iqama_number', 'LIKE', $v . '%')))
            ->when($filters['region'] ?? null, fn($q, $v) => $q->whereHas('captain.regions.quadrant', fn($q) => $q->where('quadrants.id', $v)))
            ->when($filters['area'] ?? null, fn($q, $v) => $q->whereHas('captain.regions', fn($q) => $q->where('regions.id', $v)))
            ->when($filters['job_type'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('captain_employment_type_id', $v)))
            ->when($filters['nationality'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('nationality_id', $v)))
            ->when($filters['on_duty_from'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('date_of_joining', '>=', now()->parse($v)->format('Y-m-d'))))
            ->when($filters['work_status'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('status', $v)))
            ->when($filters['payment_status'] ?? null, function ($query, $status) {
                match ($status) {
                    'Payable' => $query->where('balance_commission', '>', 0),
                    'Tally' => $query->where('balance_commission', '=', 0),
                    default => null,
                };
            })
            ->first();
    }

}
