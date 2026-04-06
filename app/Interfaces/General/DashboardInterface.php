<?php

namespace App\Interfaces\General;

use App\Services\General\DTO\DateRangeDTO;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface DashboardInterface
{
    // main dashboard
    public function getStats(string $fromDate, string $toDate): Collection;
    public function getClientStats(string $fromDate, string $toDate): Collection;

    // over all dashboard
    public function getOverallStatistic(DateRangeDTO $range, ?int $quadrant): Collection;
    public function getOverallActiveClientsCount(DateRangeDTO $range, ?int $quadrant): int;
    public function getQuadrants(): Collection;
    public function getClients(DateRangeDTO $range, ?int $clientId, int $pageSize): LengthAwarePaginator;
    public function getTotalOrders(DateRangeDTO $range, ?int $clientId): int;

    // client dashboard
    public function getClientDashboardStatistic(DateRangeDTO $range, ?int $clientId): Collection;
    public function getActiveBranchCount(DateRangeDTO $range, ?int $clientId): int;
    public function getActiveClients(DateRangeDTO $range): Collection;
    public function getClientShops(DateRangeDTO $range, ?int $clientId, ?int $shopId, int $pageSize = 10): LengthAwarePaginator;

}
