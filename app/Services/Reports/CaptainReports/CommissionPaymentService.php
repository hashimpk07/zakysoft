<?php

namespace App\Services\Reports\CaptainReports;

use App\CaptainCommissionPayment;
use App\CaptainSalaryPayment;
use App\Http\Resources\Reports\CaptainReports\CaptainCommissionPaymentResource;
use App\Http\Resources\Reports\CaptainReports\SalaryPaymentResource;
use App\Interfaces\ListInterface;
use App\Interfaces\Reports\CaptainReports\PaymentInterface;
use Illuminate\Http\Request;

final class CommissionPaymentService
{
    public function __construct(protected readonly PaymentInterface $repository)
    {
    }

    public function getCommissionPaymentReport(Request $request)
    {
        $filters = $request->only([
            'from_date',
            'to_date',
            'captain',
            'paid_by',
            'region',
            'payment_type',
            'invoice_number',
            'per_page',
        ]);

        $raw = CaptainCommissionPaymentResource::collection($this->repository->getCommissionPaymentReport($filters))->response()->getData(true);

        return [
            'reports' => $raw['data'],
            'pagination' => $raw['meta'],
        ];
    }

    public function printCommissionPaymentReport(CaptainCommissionPayment $payment)
    {
        $payment->loadMissing([
            'commission',
            'captain',
            'settledBy',
            'paymentMode'
        ]);

        $pdf = \PDF::loadView('prints.commission.invoice', compact('commission'))
            ->setOptions([
                'margin-top' => 0,
                'margin-left' => 0,
                'margin-right' => 0,
                'margin-bottom' => 0
            ]);

        return base64_encode($pdf->output());
    }

    public function getFilters()
    {
        $captains = $this->repository->getCaptains()->map(function ($captain) {
            return [
                'id' => $captain->id,
                'name' => optional($captain->user)->name,
            ];
        });

        $settledBy = $this->repository->getSettledByUsers()->map(function ($user) {
            return [
                'id' => $user->settled_by,
                'name' => optional($user->settledBy)->name,
            ];
        });

        $listInterface = app(ListInterface::class);
        $regions = $listInterface->getQuadrant();
        $paymentTypes = $listInterface->getPaymentType();

        return [
            'captains' => $captains,
            'settled_by' => $settledBy,
            'regions' => $regions,
            'payment_types' => $paymentTypes,
        ];
    }

    public function getSalaryPayments(Request $request): array
    {
        $filters = $request->only([
            'from_date',
            'to_date',
            'captain',
            'paid_by',
            'region',
            'payment_type',
            'invoice_number',
            'per_page',
        ]);

        $data = SalaryPaymentResource::collection($this->repository->getSalaryPayments($filters))->response()->getData(true);

        return [
            'reports' => $data['data'],
            'pagination' => $data['meta'],
        ];
    }

    public function printSalaryPaymentReport(CaptainSalaryPayment $payment)
    {
        $payment->loadMissing([
            'captain',
            'paidBy',
            'paymentMode'
        ]);

        $pdf = \PDF::loadView('prints.commission.salary-invoice', compact('salary'))
            ->setOptions([
                'margin-top' => 0,
                'margin-left' => 0,
                'margin-right' => 0,
                'margin-bottom' => 0
            ]);

        return base64_encode($pdf->output());
    }

    public function getSalaryFilters()
    {
        $captains = $this->repository->getCaptains()->map(function ($captain) {
            return [
                'id' => $captain->id,
                'name' => optional($captain->user)->name,
            ];
        });

        $settledBy = $this->repository->getSalarySettledByUsers()->map(function ($user) {
            return [
                'id' => $user->paid_by,
                'name' => optional($user->paidBy)->name,
            ];
        });

        $listInterface = app(ListInterface::class);
        $regions = $listInterface->getQuadrant();
        $paymentTypes = $listInterface->getPaymentType();

        return [
            'captains' => $captains,
            'settled_by' => $settledBy,
            'regions' => $regions,
            'payment_types' => $paymentTypes,
        ];
    }

}
