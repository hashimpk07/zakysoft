<?php
namespace App\Interfaces\Reports\CaptainReports;

use App\CaptainCommission;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface MakePaymentInterface
{
    public function getOrderStatistics(array $filters);
    public function getCaptainBalanceStatistics(array $filters);

    public function getCaptainPayments(array $filters, int $perPage = 20): LengthAwarePaginator;
    public function getCaptainsSalaryDetails(array $captainIds, array $filters): Collection;

     public function getLatestCommission(int $captainId): ?CaptainCommission;
 
    public function settleCommission(CaptainCommission $commission, array $data): CaptainCommission;
 
    public function insertCommissionPayments(array $payments): void;
 
    public function createSalaryPayment(array $data): object;
 
    public function insertSalaryPaymentDates(array $dates): void;
 
    public function salaryPaymentDateExists(int $captainId, string $date): bool;

}