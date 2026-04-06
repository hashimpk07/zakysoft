<?php

namespace App\Interfaces\General;

use App\Services\General\DTO\DateRangeDTO;
use Illuminate\Support\Collection;

interface DashboardManagementInterface
{
    // client
    public function getStatistic(DateRangeDTO $range, ?int $clientId): Collection;
    public function getActiveBranchCount(DateRangeDTO $range, ?int $clientId): int;
    public function getActiveClientsCount(DateRangeDTO $range, ?int $clientId): int;
    public function getActiveClients(DateRangeDTO $range): Collection;
    public function getOrderAggregates(DateRangeDTO $range, ?int $clientId): object;
    public function getServingRegionsCount(DateRangeDTO $range, ?int $clientId): int;
    public function getTotalDeliveredOrders(DateRangeDTO $range): int;
    public function getClientWeightsCount(DateRangeDTO $range, ?int $clientId): int;
    public function getBranchOrdersCount(DateRangeDTO $range, ?int $clientId): int;
    public function getBranchesCount(?int $clientId): int;

    // 3pl 

    public function getStatisticWithCompany(DateRangeDTO $range, ?int $clientId, ?int $company): Collection;
    public function getTotalDeliveredOrdersWithCompany(DateRangeDTO $range, ?int $clientId, ?int $company): int;
    public function getThirdPartyCompanies(): Collection;
    public function getCaptainsByEmploymentType(): array;
    public function getActiveCaptainsCount(?int $company): int;
    public function getCaptainWorkingHours(DateRangeDTO $range, ?int $company): float;
    public function getTotalDeliveredWithCaptain(DateRangeDTO $range): int;
    public function getOrdersPerCaptain(DateRangeDTO $range, ?int $clientId, ?int $company): Collection;
    public function getClientWeightsCountWithCompany(DateRangeDTO $range, ?int $clientId, ?int $company): int;
}