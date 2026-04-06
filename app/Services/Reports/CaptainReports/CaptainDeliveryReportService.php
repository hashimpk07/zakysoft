<?php

namespace App\Services\Reports\CaptainReports;

use App\Captain;
use App\Http\Resources\Reports\CaptainReports\CaptainDeliveryReportResource;
use App\Interfaces\Reports\CaptainReports\CaptainDeliveryInterface;
use App\Interfaces\Reports\CaptainReports\CaptainReportDeliveryInterface;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

final class CaptainDeliveryReportService
{
    public function __construct(protected readonly CaptainDeliveryInterface $interface, protected readonly CaptainReportDeliveryInterface $interfaceV2) {}

    public function getCaptainDeliveryReport(Request $request)
    {
        $reports = $this->interface->getCaptainDeliveryReport($request->only(['captain', 'employee_id', 'name', 'iqama', 'region', 'area', 'job_type', 'nationality', 'on_duty_from', 'work_status', 'payment_status']), $request->get('per_page', 20));

        $data = CaptainDeliveryReportResource::collection($reports)->response()->getData(true);

        return [
            'reports' => $data['data'],
            'pagination' => $data['meta'],
        ];
    }

    public function getStatistics(Request $request): array
    {
        $filters = $request->only(['captain', 'employee_id', 'name', 'iqama', 'region', 'area', 'job_type', 'nationality', 'on_duty_from', 'work_status', 'payment_status']);
        $orders = $this->interface->getOrderStatistics($filters);
        $balance = $this->interface->getCaptainBalanceStatistics($filters);

        return [
            'attended_orders' => number_format($orders->attended_orders ?? 0, 2),
            'total_bill_amount' => number_format($orders->total_bill_amount ?? 0, 2),
            'total_store_payment' => number_format($orders->total_store_payment ?? 0, 2),
            'total_cash_on_delivery' => number_format($orders->total_cash_on_delivery ?? 0, 2),
            'total_payment_done_by_leajlak_span' => number_format($orders->total_payment_done_by_leajlak_span ?? 0, 2),
            'total_given_custody_amount' => number_format($balance->total_given_custody_amount ?? 0, 2),
            'total_payable' => number_format($balance->total_payable ?? 0, 2),
            'total_receivable' => number_format($balance->total_receivable ?? 0, 2),
        ];
    }

    public function getDeliverCaptainStatistics(Captain $captain, array $filters): array
    {
        $dateRange = getSystemTimeRange($filters['from_date'] ?? null, $filters['to_date'] ?? null);
        $orders = $this->interface->getOrderDeliveryStatistics($captain->id, $filters, $dateRange);
        $captainStats = $this->interface->getCaptainStatistics($captain->id, $filters, $dateRange);
        $balance = $this->interface->getLatestBalance($captain->id);
        return [
            'attended_orders' => $orders->attended_orders ?? 0,
            'total_bill_amount' => $orders->total_bill_amount ?? 0,
            'total_store_payment' => $orders->total_store_payment ?? 0,
            'total_cash_on_delivery' => $orders->total_cash_on_delivery ?? 0,
            'total_payment_done_by_leajlak_span' => $orders->total_payment_done_by_leajlak_span ?? 0,
            'total_given_custody_amount' => $captainStats->total_given_custody_amount ?? 0,
            'total_receivable' => $captainStats->total_receivable ?? 0,
            'total_payable' => $captainStats->total_payable ?? 0,
            'accounts_receivable' => $balance && $balance->balance > 0 ? abs($balance->balance) : 0,
            'accounts_payable' => $balance && $balance->balance < 0 ? abs($balance->balance) : 0,
        ];
    }

    public function getDeliverReportOrdersByCaptain(Captain $captain, array $filters, $perPage = 20): LengthAwarePaginator
    {
        $dateRange = getSystemTimeRange($filters['from_date'] ?? null, $filters['to_date'] ?? null);

        return $this->interface->getDeliveredOrdersByCaptain($captain->id, $filters, $dateRange);
    }

    public function receivePayment(Captain $captain, array $data): void
    {
        $transferred = $data['transferred'];
        $paymentMode = $data['payment_mode'];
        $referenceNo = $data['reference_no'] ?? '';
        $paymentType = $data['payment_type'];
        $attachments = $data['attachments'] ?? [];

        $balance = $this->interface->getLatestCaptainOrderPaymentByCaptain($captain->id);

        if (!$balance) {
            throw new \Exception('No payment record found for captain');
        }

        // Business Logic
        $balance->type = $paymentType;
        $balance->transferring_amount += abs($transferred);
        $balance->payment_mode_id = $paymentMode;
        $balance->reference_no = $referenceNo;

        $balance->balance = $balance->balance < 0 ? $balance->balance + $transferred : $balance->balance - $transferred;

        $balance->updated_by = Auth::id();

        $this->interface->saveCaptainOrderPayment($balance);

        // Attachments Handling
        if (!empty($attachments)) {
            $uploaded = [];

            foreach ($attachments as $attachment) {
                $uploaded[] = [
                    'attachment_path' => $attachment->storePublicly('public/captain_order_settlement_attachment'),
                ];
            }

            $this->interface->createCaptainOrderPaymentAttachments($balance, $uploaded);
        }
    }

    public function getCaptainDeliveryReportV2(Request $request)
    {
        return $this->interfaceV2->getCaptainDeliveryReport($request->only(['captain', 'employee_id', 'name', 'iqama', 'region', 'area', 'job_type', 'nationality', 'on_duty_from', 'work_status', 'payment_status']), $request->get('per_page', 20));
    }

    public function getStatisticsV2(Request $request): array
    {
        $filters = $request->only(['captain', 'employee_id', 'name', 'iqama', 'region', 'area', 'job_type', 'nationality', 'on_duty_from', 'work_status', 'payment_status']);

        $stats = $this->interfaceV2->getStatistics($filters);

        return [
            'attended_orders' => number_format($stats->attended_orders ?? 0, 2),
            'total_bill_amount' => number_format($stats->total_bill_amount ?? 0, 2),
            'total_store_payment' => number_format($stats->total_store_payment ?? 0, 2),
            'total_cash_on_delivery' => number_format($stats->total_cash_on_delivery ?? 0, 2),
            'total_payment_done_by_leajlak_span' => number_format($stats->total_payment_done_by_leajlak_span ?? 0, 2),
            'total_given_custody_amount' => number_format($stats->total_given_custody_amount ?? 0, 2),
            'total_receivable' => number_format($stats->total_receivable ?? 0, 2),
            'total_payable' => number_format($stats->total_payable ?? 0, 2),
        ];
    }
}
