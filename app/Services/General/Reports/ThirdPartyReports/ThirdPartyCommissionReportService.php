<?php

namespace App\Services\General\Reports\ThirdPartyReports;

use App\Interfaces\General\Reports\ThirdPartyReportsInterface;
use Illuminate\Support\Facades\DB;


final class ThirdPartyCommissionReportService
{
   
    public function __construct(protected readonly ThirdPartyReportsInterface $interface) {}

    public function getAllCaptainCommissionReport($filters, $perPage)
    {
       $captains = $this->interface->getAllCaptainCommissionReport($filters, $perPage);

        $captains->getCollection()->transform(function ($captain) {
            $balance = ($captain->total_commission ?? 0) - ($captain->paid_commission ?? 0);
            $captain->balance = $balance;
            $captain->payment_status = $balance > 0 ? 'Payable' : 'Tally';
            return $captain;
        });

        return $captains;
    }
    
    public function getAllCaptainCommissionCountSummary($filters)
    {
        $stats = $this->interface->getAllCaptainCommissionCountSummary($filters);

        $balance = $this->interface->getAllCaptainCommissionTotalBalance($filters);

        return [
            'attended_orders' => $stats->attended_orders ?? 0,
            'total_avg_commission' => number_format($stats->total_avg_commission ?? 0,2),
            'total_commission' => number_format($stats->total_commission ?? 0,2),
            'total_payed_amount' => number_format($stats->total_payed_amount ?? 0,2),
            'total_payable_commission' => number_format($balance->total_payable_commission ?? 0,2)
        ];

       // return $this->interface->getAllCaptainCommissionCountSummary($filters);
    }

    public function getSpecificCaptainCommissionReport(int $captainId, array $filters)
    {
        $perPage = $filters['per_page'] ?? 20;

        $statistics = $this->interface->getSpecificCaptainCommissionStatistics($captainId,$filters);
        $orders = $this->interface->getSpecificCaptainCommissionOrders($captainId,$filters,$perPage);

        $payable = $statistics->total_commission - $statistics->paid_commission;

        return [
            'count' => [
                'attended_orders' => (int)$statistics->attended_orders,
                'avg_commission' => round($statistics->avg_commission,2),
                'total_commission' => round($statistics->total_commission,2),
                'paid_commission' => round($statistics->paid_commission,2),
                'payable_commission' => round($payable,2)
            ],
            'orders' => $orders
        ];
    }

    public function getThirdPartyCommissionReport($filters, $perPage)
    {
        return $this->interface->getThirdPartyCommissionReport($filters, $perPage);
    }
    
    public function getThirdPartyCommissionCountSummary($filters)
    {
        $statistics = $this->interface->getThirdPartyCommissionCount($filters);
        $balance = $this->interface->getThirdPartyCommissionBalance($filters);

        if (!$statistics) {
            return [
                'attended_orders' => 0,
                'total_avg_commission' => 0,
                'total_commission' => 0,
                'total_payed_amount' => 0,
                'total_payable_commission' => 0,
            ];
        }

        return [
            'attended_orders' => $statistics->attended_orders,
            'total_avg_commission' => number_format($statistics->total_avg_commission,2),
            'total_commission' => number_format($statistics->total_commission,2),
            'total_payed_amount' => number_format($statistics->total_payed_amount,2),
            'total_payable_commission' => $balance->total_payable_commission
                ? number_format($balance->total_payable_commission,2)
                : 0,
        ];
    }

    public function getSpecificThirdPartyCompanyCommissionReport($thirdPartyCompanyId,$filters,$perPage)
    {
        $stats = $this->interface->getSpecificThirdPartyCompanyCount($thirdPartyCompanyId,$filters);
        $orders = $this->interface->getSpecificThirdPartyCompanyOrders($thirdPartyCompanyId,$filters,$perPage);

        return [
            'statistics'=>[
                'attended_orders'=>$stats->attended_orders ?? 0,
                'avg_commission_order'=>number_format($stats->avg_commission ?? 0,2),
                'total_commission'=>number_format($stats->total_commission ?? 0,2),
                'paid_commission'=>number_format($stats->paid_commission ?? 0,2),
                'payable_commission'=>number_format($stats->payable_commission ?? 0,2),
            ],
            'orders'=>$orders
        ]; 
    }

    public function createThirdPartyCompanyCommissionPayment($companyId, $data)
    {

        DB::beginTransaction();

        try {

            $commission = $this->interface->getLatestThirdPartyCompanyCommission($companyId);

            $previousBalance = $commission->balance;

            $commission->settled_amount += $data['transferred'];
            $commission->payment_mode_id = $data['payment_mode'];
            $commission->reference_no = $data['reference_no'] ?? null;
            $commission->balance -= $data['transferred'];
            $commission->settled_by = auth()->id();
            $commission->settled_at = now();

            $this->interface->updateThirdPartyCompanyCommission($commission);

            $paymentData = [
                'commission_id' => $commission->id,
                'third_party_company_id' => $companyId,
                'prev_balance' => $previousBalance,
                'amount_paid' => $data['transferred'],
                'reference_no' => $data['reference_no'] ?? null,
                'balance' => $commission->balance,
                'payment_mode_id' => $data['payment_mode'],
                'order_count' => $data['orders_count'] ?? null,
                'from_date' => $data['date_from'] ?? null,
                'to_date' => $data['date_to'] ?? null,
                'settled_by' => auth()->id(),
                'settled_at' => now()
            ];

            $this->interface->createThirdPartyCompanyCommissionPayment($paymentData);

            $attachmentsUpload = [];

            if (!empty($data['attachments'])) {
                foreach ($data['attachments'] as $file) {
                    $attachmentsUpload[] = [
                        'path' => str_replace(
                            'public',
                            'storage',
                            $file->storePublicly(
                                'public/3pl_commission_settlement_attachment'
                            )
                        )
                    ];
                }

                $this->interface->createThirdPartyCompanyCommissionAttachments($commission, $attachmentsUpload);
            }

            DB::commit();

            return [
                'commission_id' => $commission->id,
                'company_id' => $companyId,
                'amount_paid' => $data['transferred'],
                'previous_balance' => $previousBalance,
                'remaining_balance' => $commission->balance,
                'payment_mode' => $data['payment_mode'],
                'reference_no' => $data['reference_no'] ?? null,
                'settled_by' => auth()->id(),
                'settled_at' => now()
            ];

        } catch (\Exception $e) {

            DB::rollBack();
            throw $e;
        }
    }

}
