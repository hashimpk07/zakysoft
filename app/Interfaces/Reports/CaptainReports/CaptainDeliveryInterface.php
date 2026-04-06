<?php

namespace App\Interfaces\Reports\CaptainReports;

use App\CaptainOrderPayment;
use Illuminate\Pagination\LengthAwarePaginator;

interface CaptainDeliveryInterface
{
    public function getCaptainDeliveryReport(array $filters, int $perPage = 20): LengthAwarePaginator;

    public function getCaptainBalanceStatistics(array $filters): object;

    public function getOrderStatistics(array $filters): object;

    public function getOrderDeliveryStatistics(int $captainId, array $filters, array $dateRange): object;
    public function getDeliveredOrdersByCaptain(int $captainId, array $filters, array $dateRange, int $perPage =20): LengthAwarePaginator;

    public function getLatestBalance(int $captainId): ?object;
    public function getPreviousEditableBalance(int $captainId): ?object;

    public function getCaptainStatistics(int $captainId, array $filters, array $dateRange): object;

     public function getLatestCaptainOrderPaymentByCaptain(int $captainId): ?CaptainOrderPayment;

    public function saveCaptainOrderPayment(CaptainOrderPayment $payment): bool;

    public function createCaptainOrderPaymentAttachments(CaptainOrderPayment $payment, array $attachments): void;

}