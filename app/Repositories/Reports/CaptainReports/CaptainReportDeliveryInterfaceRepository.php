<?php

namespace App\Repositories\Reports\CaptainReports;

use App\CaptainReport;
use App\Interfaces\Reports\CaptainReports\CaptainReportDeliveryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

final class CaptainReportDeliveryInterfaceRepository implements CaptainReportDeliveryInterface
{
    public function getCaptainDeliveryReport(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return CaptainReport::query()
            ->with('captain.regions.quadrant', 'captain.employmentType', 'captain.nationality')
            ->when($filters['employee_id'] ?? null, fn($q, $v) => $q->whereLike('captain.code', $v))
            ->when($filters['captain'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('captains.id', $v)))
            ->when($filters['name'] ?? null, fn($q, $v) => $q->whereLike(['captain.user.name'], $v))
            ->when($filters['iqama'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('iqama_number', 'LIKE', '%' . $v . '%')))
            ->when($filters['region'] ?? null, fn($q, $v) => $q->whereHas('captain.regions.quadrant', fn($q) => $q->where('quadrants.id', $v)))
            ->when($filters['area'] ?? null, fn($q, $v) => $q->whereHas('captain.regions', fn($q) => $q->where('regions.id', $v)))
            ->when($filters['job_type'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('captain_employment_type_id', $v)))
            ->when($filters['nationality'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('captains.nationality_id', $v)))
            ->when($filters['on_duty_from'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('captains.date_of_joining', '>=', now()->parse($v)->format('Y-m-d'))))
            ->when($filters['work_status'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('captains.status', $v)))
            ->when($filters['payment_status'] ?? null, function ($query, $status) {
                match ($status) {
                    'Receivable' => $query->where('balance', '>', 0),
                    'Payable' => $query->where('balance', '<', 0),
                    'Tally' => $query->where('balance', '=', 0),
                    default => null,
                };
            })
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getStatistics(array $filters): object
    {
        return CaptainReport::query()
            ->leftJoin('captains', 'captain_reports.captain_id', '=', 'captains.id')
            ->when($filters['captain'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q) => $q->where('captains.id', $v)))
            ->when($filters['employee_id'] ?? null, fn($q, $v) => $q->whereLike(['captain.code'], $v))
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
                    'Receivable' => $query->where('balance', '>', 0),
                    'Payable' => $query->where('balance', '<', 0),
                    'Tally' => $query->where('balance', '=', 0),
                    default => null,
                };
            })
            ->selectRaw(
                '
                SUM(attended_orders)                                        AS attended_orders,
                SUM(total_bill_amount)                                      AS total_bill_amount,
                SUM(store_payments)                                         AS total_store_payment,
                SUM(total_received_amount_from_leajlak)                     AS total_received_amount_from_leajlak,
                SUM(total_payed_amount_from_leajlak)                        AS total_payed_amount_from_leajlak,
                SUM(credited_to_leajlak)                                    AS total_payment_done_by_leajlak_span,
                SUM(cod)                                                    AS total_cash_on_delivery,
                SUM(captains.given_custodyamount)                           AS total_given_custody_amount,
                SUM(balance)                                                AS balance,
                SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END)         AS total_receivable,
                SUM(CASE WHEN balance < 0 THEN ABS(balance) ELSE 0 END)    AS total_payable
            ',
            )
            ->first();
    }
}
