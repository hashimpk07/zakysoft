<?php

namespace App\Services\Reports\CaptainReports;

use App\Http\Resources\Reports\CaptainReports\CaptainPaymentResource;
use App\Interfaces\Reports\CaptainReports\MakePaymentInterface;
use App\PaymentMode;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class MakePaymentService
{

    public function __construct(protected readonly MakePaymentInterface $repository)
    {

    }
    public function getStatistics(Request $request): array
    {
        $filters = $request->only([
            'from_date',
            'to_date',
            'captain',
            'captain_id',
            'region',
            'job_type',
            'status',
            'on_duty_from',
            'vehicle_type',
            'payment_status',
            'removed_zero_captain',
            'submit',
        ]);

        $orders = $this->repository->getOrderStatistics($filters);
        $balance = $this->repository->getCaptainBalanceStatistics($filters);

        $attendedOrders = $orders->attended_orders ?? 0;
        $totalCommission = $orders->total_commission ?? 0;

        return [
            'attended_orders' => $attendedOrders,
            'total_avg_commission' => $attendedOrders > 0 ? round($totalCommission / $attendedOrders, 2) : 0,
            'total_commission' => number_format($totalCommission, 2),
            'captains_count' => $balance->captains_count ?? 0,
            'total_payable_commission' => number_format($balance->total_payable_commission ?? 0, 2),
        ];
    }

    public function getCaptainPayments(Request $request): array
    {
        $filters = $request->only([
            'from_date',
            'to_date',
            'captain',
            'captain_id',
            'region',
            'job_type',
            'status',
            'on_duty_from',
            'vehicle_type',
            'payment_status',
            'removed_zero_captain',
        ]);

        // Mirror original: removed_zero_captain defaults to 1 when not explicitly set to 0
        if (!isset($filters['removed_zero_captain']) || $filters['removed_zero_captain'] == 1) {
            $filters['removed_zero_captain'] = 1;
        } else {
            $filters['removed_zero_captain'] = 0;
        }

        $data = CaptainPaymentResource::collection(
            $this->repository->getCaptainPayments(filters: $filters, perPage: $request->get('per_page', 20))
        )->response()->getData(true);

        return [
            'reports' => $data['data'],
            'pagination' => $data['meta'],
        ];
    }

    public function getSalaryPreview(array $filters): array
    {
        $from_date = $filters['from_date'] ?? null;
        $to_date   = $filters['to_date']   ?? null;
        $captainIds = $filters['captains'] ?? [];
 
        $captains = $this->repository->getCaptainsSalaryDetails($captainIds, $filters);
 
        $captainPayments = collect($filters['captain_payments'] ?? [])->mapWithKeys(function ($item) {
            return [$item['id'] => [
                'worked_days'        => $item['worked_days'],
                'per_day_salary'     => $item['per_day_salary'],
                'paying_salary'      => $item['paying_salary'],
                'paying_amount'      => $item['paying_amount'],
                'payment_mode'       => $item['payment_mode'],
                'payment_mode_name'  => PaymentMode::find($item['payment_mode'])->name,
            ]];
        })->all();
 
        return [
            'captains'            => $captains,
            'captain_payments'    => $captainPayments,
            'date_filter_enabled' => $from_date && $to_date,
        ];
    }

     public function confirmPayment(array $data): void
    {
        $captainPayments  = $data['captainPayments'] ?? [];
        $from_date        = $data['from_date'] ?? null;
        $to_date          = $data['to_date'] ?? null;
        $salary_from_date = isset($data['salary_from_date']) ? Carbon::parse($data['salary_from_date']) : null;
        $salary_to_date   = isset($data['salary_to_date'])   ? Carbon::parse($data['salary_to_date'])   : null;
        $now              = now();
 
        DB::connection('mysql::write')->transaction(function () use (
            $captainPayments, $from_date, $to_date, $salary_from_date, $salary_to_date, $now
        ) {
            $commissionPayments = [];
 
            foreach ($captainPayments as $payment) {
                $payment = (object) $payment;
 
                if ($payment->paying_amount > 0) {
                    $commission  = $this->repository->getLatestCommission($payment->id);
                    $prevBalance = $commission->balance;
 
                    $commission = $this->repository->settleCommission($commission, [
                        'amount_paid'  => $payment->paying_amount,
                        'payment_mode' => $payment->payment_mode,
                        'settled_by'   => auth()->id(),
                        'settled_at'   => $now,
                    ]);
 
                    $commissionPayments[] = [
                        'commission_id'   => $commission->id,
                        'captain_id'      => $payment->id,
                        'prev_balance'    => $prevBalance,
                        'amount_paid'     => $payment->paying_amount,
                        'balance'         => $commission->balance,
                        'payment_mode_id' => $payment->payment_mode,
                        'order_count'     => $payment->orders_count,
                        'from_date'       => $from_date,
                        'to_date'         => $to_date,
                        'settled_by'      => auth()->id(),
                        'settled_at'      => $now,
                    ];
 
                    $this->updateCaptainCommissionAction->execute([
                        'captain_id' => $payment->id,
                        'amount_paid'=> $payment->paying_amount,
                    ]);
                }
 
                if ($salary_from_date && $salary_to_date && $payment->paying_salary > 0) {
                    $salaryPayment = $this->repository->createSalaryPayment([
                        'captain_id'         => $payment->id,
                        'from_date'          => $salary_from_date,
                        'to_date'            => $salary_to_date,
                        'worked_days'        => $payment->worked_days,
                        'salary_per_day'     => $payment->per_day_salary,
                        'total_salary_paid'  => $payment->paying_salary,
                        'payment_mode_id'    => $payment->payment_mode,
                        'paid_by'            => auth()->id(),
                        'created_at'         => $now,
                    ]);
 
                    $paymentDates = [];
                    $current_date = $salary_from_date->copy();
 
                    while ($current_date->lte($salary_to_date)) {
                        if (!$this->repository->salaryPaymentDateExists($payment->id, $current_date->toDateString())) {
                            $paymentDates[] = [
                                'salary_payment_id' => $salaryPayment->id,
                                'captain_id'        => $payment->id,
                                'per_day_salary'    => $payment->per_day_salary,
                                'paid_on_date'      => $current_date->copy(),
                            ];
                        }
                        $current_date->addDay();
                    }
 
                    $this->repository->insertSalaryPaymentDates($paymentDates);
                }
            }
 
            if (!empty($commissionPayments)) {
                $this->repository->insertCommissionPayments($commissionPayments);
            }
        });
    }
}
