<?php

namespace App\Interfaces\Reports\CaptainReports;

use App\CaptainCommission;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface CaptainCommissionInterface{
    public function getFilteredCaptains(array $filters, int $perPage = 20): LengthAwarePaginator;
    public function getCaptainBalanceStatistics(array $filters): object;
     public function getOrderStatistics(array $filters): object;
     public function getCommissionOrders(int $captainId, array $filters, array $dateRange, int $perPage = 20): LengthAwarePaginator;

    public function getCommissionOrderStatistics(int $captainId, array $filters, array $dateRange): object;

    public function getCommissionSummary(int $captainId, array $dateRange): object;

    public function getTotalBonus(int $captainId, array $dateRange): float;

    public function getBonusRecords(int $captainId, array $dateRange): Collection;

    public function getPreviousEditableCommission(int $captainId): ?object;

     public function getLatestCommission(int $captainId): ?CaptainCommission;

    public function settleCommission(CaptainCommission $commission, array $data): CaptainCommission;

    public function createPaymentRecord(array $data): void;

    public function storeAttachments(CaptainCommission $commission, array $attachments): void;

    public function save(CaptainCommission $commission): bool;

    public function getCommissionReportV2(array $filters, int $perPage = 20): LengthAwarePaginator;

    public function getStatisticsV2(array $filters): object;
    

     
}