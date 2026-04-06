<?php

namespace App\Services\General\Dashboard;

use App\Interfaces\General\DashboardManagementInterface;
use App\OrderStatus;
use App\Services\General\DTO\DateRangeDTO;
use Illuminate\Support\Collection;

class DashboardManagementService
{
    public function __construct(protected readonly DashboardManagementInterface $interface)
    {
    }

    public function getClientFullStats(DateRangeDTO $range, ?int $clientId): array
    {
        $statistic = $this->buildStatistic($range, $clientId);
        $totalOrders = $statistic->sum('count');
        $aggregates = $this->interface->getOrderAggregates($range, $clientId);
        $totalDelivered = $this->interface->getTotalDeliveredOrders($range);
        $clientWeightsCount = $this->interface->getClientWeightsCount($range, $clientId);
        $branchOrdersCount = $this->interface->getBranchOrdersCount($range, $clientId);
        $branchesCount = $this->interface->getBranchesCount($clientId);

        return [
            'total_orders' => $totalOrders,
            'delivery_success' => $this->calcDeliverySuccess($statistic, $totalOrders),
            'active_branch_count' => $this->interface->getActiveBranchCount($range, $clientId),
            'active_clients_count' => $this->interface->getActiveClientsCount($range, $clientId),
            'active_clients' => $this->interface->getActiveClients($range),
            'serving_regions_count' => $this->interface->getServingRegionsCount($range, $clientId),
            'avg_orders_per_branch' => $aggregates->total_shops > 0
                ? round($totalOrders / $aggregates->total_shops, 2)
                : 0,
            'avg_orders_per_client' => $aggregates->total_clients > 0
                ? round($totalOrders / $aggregates->total_clients, 2)
                : 0,
            'average_client_weight' => $totalDelivered > 0
                ? round(($clientWeightsCount * 100) / $totalDelivered, 2)
                : 0,
            'average_branch_weight' => $branchesCount > 0
                ? round($branchOrdersCount / $branchesCount / 100, 2)
                : 0,
            'statistic' => [
                'delivered' => $statistic[OrderStatus::DELIVERED]['count'] ?? 0,
                'canceled' => $statistic[OrderStatus::CANCEL]['count'] ?? 0,
                'returned_to_client' => $statistic[OrderStatus::CLIENT_RETURN_ACCEPTED]['count'] ?? 0,
                'return_to_client' => $statistic[OrderStatus::RETURN_TO_CLIENT]['count'] ?? 0,
                'cancel_request_accepted' => $statistic[OrderStatus::CANCEL_REQUEST_ACCEPTED]['count'] ?? 0,
            ],
        ];
    }

    private function buildStatistic(DateRangeDTO $range, ?int $clientId): Collection
    {
        return $this->interface
            ->getStatistic($range, $clientId)
            ->mapWithKeys(fn($item) => [
                $item->id => ['name' => $item->name, 'count' => $item->count],
            ]);
    }

    private function calcDeliverySuccess(Collection $statistic, int $totalOrders): float
    {
        if ($totalOrders === 0)
            return 0;

        return round(
            (($statistic[OrderStatus::DELIVERED]['count'] ?? 0) / $totalOrders) * 100,
            2
        );
    }

    private function calcAvgPerHour(int $totalOrders, int $totalHours): float
    {
        if ($totalOrders === 0)
            return 0;

        return round($totalOrders / ($totalHours + 1), 2);
    }

    // 3pl 

    public function getCaptainStats(DateRangeDTO $range, ?int $clientId, ?int $company): array
    {
        $statistic = $this->buildStatisticWithCompany($range, $clientId, $company);
        $totalOrders = $statistic->sum('count');
        $totalDelivered = $this->interface->getTotalDeliveredWithCaptain($range);
        $ordersPerCaptain = $this->interface->getOrdersPerCaptain($range, $clientId, $company);
        $clientWeights = $this->interface->getClientWeightsCountWithCompany($range, $clientId, $company);
        $companyWeights = $this->interface->getClientWeightsCountWithCompany($range, $clientId, $company);
        $totalHoursWorked = $this->interface->getCaptainWorkingHours($range, $company);
        $totalCaptains = $ordersPerCaptain->count();
        $selectedDays = now()->parse($range->startDate)->diffInDays(now()->parse($range->endDate)) + 1;


        return [
            'total_orders' => $totalOrders,
            'delivery_success' => $this->calcDeliverySuccess($statistic, $totalOrders),
            'delivered_orders_count' => $statistic[OrderStatus::DELIVERED]['count'] ?? 0,
            'third_party_companies_count' => $this->getThirdPartyCompanies()->count(),
            'captains_by_employment_type' => $this->interface->getCaptainsByEmploymentType(),
            'active_captains_count' => $this->interface->getActiveCaptainsCount($company),
            'average_captain_online_per_hour' => round(($totalHoursWorked / 24) / 100, 2),
            'average_order_weight' => $totalOrders > 0
                ? round($totalDelivered / $totalOrders, 2)
                : 0,
            'average_client_weight' => $totalDelivered > 0
                ? round(($clientWeights * 100) / $totalDelivered, 2)
                : 0,
            'average_company_weight' => $totalDelivered > 0
                ? round(($companyWeights * 100) / $totalDelivered, 2)
                : 0,
            'average_captain_orders' => ($totalCaptains > 0 && $selectedDays > 0)
                ? round($ordersPerCaptain->sum('order_count') / ($selectedDays * $totalCaptains), 2)
                : 0,
            'statistic' => [
                'delivered' => $statistic[OrderStatus::DELIVERED]['count'] ?? 0,
                'canceled' => $statistic[OrderStatus::CANCEL]['count'] ?? 0,
                'returned_to_client' => $statistic[OrderStatus::CLIENT_RETURN_ACCEPTED]['count'] ?? 0,
                'return_to_client' => $statistic[OrderStatus::RETURN_TO_CLIENT]['count'] ?? 0,
                'cancel_request_accepted' => $statistic[OrderStatus::CANCEL_REQUEST_ACCEPTED]['count'] ?? 0,
            ],
        ];
    }

    private function buildStatisticWithCompany(DateRangeDTO $range, ?int $clientId, ?int $company): Collection
    {
        return $this->interface
            ->getStatisticWithCompany($range, $clientId, $company)
            ->mapWithKeys(fn($item) => [
                $item->id => ['name' => $item->name, 'count' => $item->count],
            ]);
    }

    public function getThirdPartyCompanies(): Collection
    {
        return $this->interface->getThirdPartyCompanies();
    }
}