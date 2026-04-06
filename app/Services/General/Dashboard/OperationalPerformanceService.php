<?php

namespace App\Services\General\Dashboard;

use App\Interfaces\General\OperationalPerformanceInterface;
use App\Services\General\DTO\OperationalDateDTO;

final class OperationalPerformanceService
{

    public function __construct(protected readonly OperationalPerformanceInterface $repository)
    {

    }
    public function getOperationalStats(OperationalDateDTO $dto): array
    {
        $totalOrders = $this->repository->getTotalOrderCounts($dto);
        $totalDeliveries = $this->repository->getTotalDeliveryCount($dto);
        $totalMTD = $this->repository->getTotalMonthlyTillDate($dto);
        $totalYTD = $this->repository->getTotalOrdersYTD($dto);
        $clientCount = $this->repository->getClientCount($dto);
        $branchCount = $this->repository->getBranchCount($dto);
        $deliveredCaptains = $this->repository->getTotalDeliveredCaptains($dto);

        return [
            'total_orders' => $totalOrders,
            'deliveries' => $totalDeliveries,
            'failed_orders' => $this->repository->getTotalFailedCount($dto),
            'success_rate' => $totalOrders > 0
                ? round(($totalDeliveries / $totalOrders) * 100, 2)
                : 0,
            'avg_orders_per_hour' => $totalOrders > 0
                ? round($totalOrders / 24, 2)
                : 0,
            'total_orders_mtd' => $totalMTD,
            'avg_daily_orders_mtd' => $totalMTD > 0
                ? round($totalMTD / $dto->dayOfMonth, 2)
                : 0,
            'total_orders_ytd' => $totalYTD,
            'avg_daily_orders_ytd' => $totalYTD > 0
                ? round($totalYTD / $dto->dayOfYear, 2)
                : 0,
            'clients' => $clientCount,
            'branches' => $branchCount,
            'serving_regions' => $this->repository->getServingRegions(),
            'orders_per_client' => $clientCount > 0
                ? round($totalDeliveries / $clientCount, 2)
                : 0,
            'orders_per_branch' => $branchCount > 0
                ? round($totalDeliveries / $branchCount, 2)
                : 0,
            'third_party_companies' => $this->repository->getThirdPartyCompaniesCount(),
            'total_captains' => $this->repository->getTotalCaptains(),
            'delivered_captains' => $deliveredCaptains,
            'avg_orders_per_captain' => $deliveredCaptains > 0
                ? round($totalDeliveries / $deliveredCaptains, 2)
                : 0,
        ];
    }
}