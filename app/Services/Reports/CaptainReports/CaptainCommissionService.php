<?php
namespace App\Services\Reports\CaptainReports;

use App\Captain;
use App\Events\CaptainCommissionPaymentCreated;
use App\Interfaces\Reports\CaptainReports\CaptainCommissionInterface;
use App\PaymentMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class CaptainCommissionService
{
    public function __construct(protected readonly CaptainCommissionInterface $interface)
    {
    }

    public function getCommissionReport(Request $request)
    {
        return $this->interface->getFilteredCaptains($request->only([
            'captain',
            'employee_id',
            'name',
            'iqama',
            'region',
            'area',
            'job_type',
            'nationality',
            'on_duty_from',
            'work_status',
            'payment_status',
            'third_party_logistic_company',
        ]), $request->get("per_page", 20));
    }

    public function getStatistics(Request $request): array
    {
        $filters = $request->only([
            'captain',
            'employee_id',
            'name',
            'iqama',
            'region',
            'area',
            'job_type',
            'nationality',
            'on_duty_from',
            'work_status',
            'payment_status',
            'third_party_company_id',
        ]);
        $orders = $this->interface->getOrderStatistics($filters);
        $balance = $this->interface->getCaptainBalanceStatistics($filters);

        return [
            'attended_orders' => number_format($orders?->attended_orders ?? 0, 2),
            'total_avg_commission' => number_format($orders?->total_avg_commission ?? 0, 2),
            'total_commission' => number_format($orders?->total_commission ?? 0, 2),
            'total_payed_amount' => number_format($orders?->total_payed_amount ?? 0, 2),
            'total_payable_commission' => number_format($balance?->total_payable_commission ?? 0, 2),
        ];
    }

    public function getCaptainCommissionReports(Captain $captain, Request $request)
    {
        $filters = $request->only(['from_date', 'to_date', 'region', 'q', 'status', 'client', 'shop']);
        $dateRange = getSystemTimeRange($filters['from_date'] ?? null, $filters['to_date'] ?? null);
        return [
            "reports" => $this->interface->getCommissionOrders($captain->id, $filters, $dateRange, $request->get("per_page", 20)),
            "statistics" => $this->getCommissionStatistics($captain, $filters),
        ];
    }

    public function getCommissionStatistics(Captain $captain, array $filters): array
    {
        $dateRange = getSystemTimeRange($filters['from_date'] ?? null, $filters['to_date'] ?? null);
        $statistics = $this->interface->getCommissionOrderStatistics($captain->id, $filters, $dateRange);
        $summary = $this->interface->getCommissionSummary($captain->id, $dateRange);
        $totalBonus = $this->interface->getTotalBonus($captain->id, $dateRange);

        return [
            'attended_orders' => $statistics->attended_orders ?? 0,
            'avg_commission' => $statistics->avg_commission ?? 0,
            'total_commission' => $statistics->total_commission ?? 0,
            'total_payed_commission' => $statistics->total_payed_commission ?? 0,
            'total_filter_commission' => $summary->total_filter_commission ?? 0,
            'total_bonus' => $totalBonus,
            'last_commission' => $summary->lastCommission,
        ];
    }

    public function getBonusRecords(Captain $captain, Request $request): array
    {
        $filters = $request->only(['from_date', 'to_date', 'region', 'q', 'status', 'client', 'shop']);

        $dateRange = getSystemTimeRange($filters['from_date'] ?? null, $filters['to_date'] ?? null);
        return [
            "bonus" => $this->interface->getBonusRecords($captain->id, $dateRange),
            "editable_commission" => $this->getPreviousEditableCommission($captain)
        ];
    }
    public function getPreviousEditableCommission(Captain $captain): ?object
    {
        return $this->interface->getPreviousEditableCommission($captain->id);
    }

    public function storeCommission(Captain $captain, array $data): void
    {
        DB::connection('mysql::write')->transaction(function () use ($captain, $data) {
            $commission = $this->interface->getLatestCommission($captain->id);
            $prevBalance = $commission->balance;

            $commission = $this->interface->settleCommission($commission, [
                'transferred' => $data['transferred'],
                'payment_mode' => $data['payment_mode'],
                'reference_no' => $data['reference_no'] ?? '',
                'settled_by' => auth()->id(),
            ]);

            $this->interface->createPaymentRecord([
                'commission_id' => $commission->id,
                'captain_id' => $commission->captain_id,
                'prev_balance' => $prevBalance,
                'amount_paid' => $data['transferred'],
                'reference_no' => $data['reference_no'] ?? '',
                'balance' => $commission->balance,
                'payment_mode_id' => $data['payment_mode'],
                'order_count' => $data['orders_count'] ?? null,
                'from_date' => $data['date_from'] ?? null,
                'to_date' => $data['date_to'] ?? null,
                'settled_by' => auth()->id(),
                'settled_at' => now(),
            ]);

            $this->interface->storeAttachments($commission, $data['attachments'] ?? []);

            CaptainCommissionPaymentCreated::dispatch($commission);
        });
    }

    public function generateReceipt(Captain $captain, array $data): array
    {
        $commission = $this->interface->getLatestCommission($captain->id);

        if (!$commission) {
            throw new \Exception("Commission not found");
        }

        // Validate transferred against balance (business rule)
        if ($data['transferred'] > $commission->balance) {
            throw new \Exception("Transferred amount exceeds balance");
        }

        // Update timestamps
        $commission->updated_at = now();

        // Business Data
        $commission->from_date = $data['date_from'];
        $commission->to_date = $data['date_to'];
        $commission->orders_count = $data['orders_count'];
        $commission->settled_amount = $data['transferred'];
        $commission->reference_no = $data['reference_no'] ?? null;

        $this->interface->save($commission);

        // العلاقات (relations)
        $paymentMode = PaymentMode::find($data['payment_mode']);

        $commission->setRelation('paymentMode', $paymentMode);
        $commission->setRelation('settledBy', Auth::user());

        // Generate PDF
        $pdf = \PDF::loadView('prints.commission.receipt', [
            'commission' => $commission
        ])->setOptions([
                    'margin-top' => 0,
                    'margin-left' => 0,
                    'margin-right' => 0,
                    'margin-bottom' => 0
                ]);

        return [
            "agreement" => base64_encode($pdf->inline())
        ];
    }

    public function getCommissionReportV2(Request $request)
    {
        $filters = $request->only([
            'captain',
            'employee_id',
            'name',
            'iqama',
            'region',
            'area',
            'job_type',
            'nationality',
            'on_duty_from',
            'work_status',
            'payment_status',
            'third_party_logistic_company',
        ]);
        return $this->interface->getCommissionReportV2($filters, $request->get("per_page", 20));
    }


    public function getStatisticsV2(Request $request): array
    {
        $filters = $request->only([
            'captain',
            'employee_id',
            'name',
            'iqama',
            'region',
            'area',
            'job_type',
            'nationality',
            'on_duty_from',
            'work_status',
            'payment_status',
            'third_party_logistic_company',
        ]);

        $raw = $this->interface->getStatisticsV2($filters);
 
        $attendedOrders  = $raw->attended_orders ?? 0;
        $totalCommission = $raw->total_commission ?? 0;
 
        return [
            'attended_orders'          => $attendedOrders,
            'total_avg_commission'     => $attendedOrders > 0
                                            ? round($totalCommission / $attendedOrders, 2)
                                            : 0,
            'total_commission'         => number_format($totalCommission, 2),
            'total_payed_amount'       => number_format($raw->total_payed_amount ?? 0, 2),
            'total_payable_commission' => number_format($raw->balance ?? 0, 2),
        ];
    }

}
