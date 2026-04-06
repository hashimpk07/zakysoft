<?php

namespace App\Repositories\Reports\CaptainReports;

use App\Captain;
use App\CaptainCommissionPayment;
use App\CaptainSalaryPayment;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Interfaces\Reports\CaptainReports\PaymentInterface;

final class PaymentRepository implements PaymentInterface
{
    public function getCommissionPaymentReport(array $filters): LengthAwarePaginator
    {
        $perPage = isset($filters['per_page']) && is_numeric($filters['per_page'])
            ? (int) $filters['per_page']
            : 20;

        return CaptainCommissionPayment::query()
            ->select([
                'id',
                'captain_id',
                'settled_by',
                'payment_mode_id',
                'settled_at',
                'amount_paid',
                'order_count',
                'from_date',
                'to_date',
            ])
            ->with([
                'settledBy:id,name',
                'paymentMode:id,name',
                'captain:id,iqama_number,user_id,region_id',
                'captain.user:id,name',
                'captain.regions:id,quadrant_id',
                'captain.regions.quadrant:id,name',
            ])
            ->whereDoesntHave('captain.captainThirdParty')
            ->when(
                $filters['from_date'] ?? null,
                fn($q, $v) =>
                $q->where('settled_at', '>=', now()->parse($v)->format('Y-m-d') . ' 06:00:00')
            )
            ->when(
                $filters['to_date'] ?? null,
                fn($q, $v) =>
                $q->where('settled_at', '<=', now()->parse($v)->addDay()->format('Y-m-d') . ' 05:59:59')
            )
            ->when(
                $filters['captain'] ?? null,
                fn($q, $v) =>
                $q->where('captain_id', $v)
            )
            ->when(
                $filters['paid_by'] ?? null,
                fn($q, $v) =>
                $q->where('settled_by', $v)
            )
            ->when(
                $filters['region'] ?? null,
                fn($q, $v) =>
                $q->whereHas(
                    'captain.regions.quadrant',
                    fn($q) =>
                    $q->where('quadrants.id', $v)
                )
            )
            ->when(
                $filters['payment_type'] ?? null,
                fn($q, $v) =>
                $q->where('payment_mode_id', $v)
            )
            ->when(
                $filters['invoice_number'] ?? null,
                fn($q, $v) =>
                $q->where('id', $v)
            )
            ->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getCaptains()
    {
        return Captain::commissionedCaptain()->with('user:id,name')->orderBy('firstname')->get();
    }

    public function getSettledByUsers()
    {
        return CaptainCommissionPayment::select('settled_by')->with('settledBy:id,name')->groupBy('settled_by')->get();
    }

    public function getSalaryPayments(array $filters): LengthAwarePaginator
    {
        $perPage = isset($filters['per_page']) && is_numeric($filters['per_page'])
            ? (int) $filters['per_page']
            : 20;

        return CaptainSalaryPayment::query()
            ->with('captain:id,firstname,iqama_number,region_id', 'paidBy:id,name', 'paymentMode:id,name', 'captain.regions:id,quadrant_id', 'captain.regions.quadrant:id,name')
            ->when($filters['from_date'] ?? null, fn($q, $v) => $q->where('created_at', '>=', now()->parse($v)->format('Y-m-d') . ' 06:00:00'))
            ->when($filters['to_date'] ?? null, fn($q, $v) => $q->where('created_at', '<=', now()->parse($v)->addDay()->format('Y-m-d') . ' 05:59:59'))
            ->when($filters['captain'] ?? null, fn($q, $v) => $q->where('captain_id', $v))
            ->when($filters['paid_by'] ?? null, fn($q, $v) => $q->where('paid_by', $v))
            ->when($filters['region'] ?? null, fn($q, $v) => $q->whereHas('captain.regions.quadrant', fn($q) => $q->where('quadrants.id', $v)))
            ->when($filters['payment_type'] ?? null, fn($q, $v) => $q->where('payment_mode_id', $v))
            ->when($filters['invoice_number'] ?? null, fn($q, $v) => $q->where('id', $v))
            ->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getSalarySettledByUsers()
    {
        return CaptainSalaryPayment::select('paid_by')->with('paidBy:id,name')->groupBy('paid_by')->get();
    }


}
