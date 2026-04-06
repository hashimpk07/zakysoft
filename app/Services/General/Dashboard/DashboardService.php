<?php
namespace App\Services\General\Dashboard;

use App\Interfaces\General\DashboardInterface;
use App\OrderStatus;
use App\Services\General\DTO\DateRangeDTO;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class DashboardService
{
    private array $statusIds = [OrderStatus::DELIVERED, OrderStatus::CANCEL, OrderStatus::RETURN_TO_CLIENT, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::CLIENT_RETURN_ACCEPTED];

    public function __construct(protected readonly DashboardInterface $dashboard) {}

    public function getStats(string $fromDate, string $toDate): array
    {
        $statistic = $this->buildStatistic($fromDate, $toDate);
        $totalOrders = $statistic->sum('count');
        $totalHours = now()
            ->parse($fromDate)
            ->diffInHours(now()->parse($toDate));

        return [
            'total_orders' => $totalOrders,
            'delivery_success' => $this->calcDeliverySuccess(statistic: $statistic, totalOrders: $totalOrders),
            'avg_orders_per_hr' => $this->calcAvgPerHour(totalOrders: $totalOrders, totalHours: $totalHours),
            'statistic' => [
                'delivered' => $statistic[OrderStatus::DELIVERED]['count'],
                'canceled' => $statistic[OrderStatus::CANCEL]['count'],
                'returned_to_client' => $statistic[OrderStatus::CLIENT_RETURN_ACCEPTED]['count'],
                'return_to_client' => $statistic[OrderStatus::RETURN_TO_CLIENT]['count'],
                'cancel_request_accepted' => $statistic[OrderStatus::CANCEL_REQUEST_ACCEPTED]['count'],
            ],
        ];
    }

    public function getClientStats(string $fromDate, string $toDate): Collection
    {
        return $this->dashboard->getClientStats(fromDate: $fromDate, toDate: $toDate)->map(
            fn($client) => [
                'name' => $client->user->name,
                'total_orders' => $client->total_orders,
                'new_orders' => $client->new_orders,
                'on_going_orders' => $client->on_going_orders,
                'delivered' => $client->delivered,
                'unsuccessful_deliveries' => $client->unsuccessful_deliveries,
                'success_rate' => $client->total_orders > 0 ? number_format(($client->delivered / $client->total_orders) * 100, 2) . '%' : 'N/A',
            ],
        );
    }

    private function buildStatistic(string $fromDate, string $toDate): Collection
    {
        $statistic = $this->dashboard->getStats($fromDate, $toDate)->mapWithKeys(
            fn($item) => [
                $item->id => ['name' => $item->name, 'count' => $item->count],
            ],
        );

        return $this->fillMissingStatuses($statistic);
    }

    private function fillMissingStatuses(Collection $statistic): Collection
    {
        OrderStatus::whereIn('id', $this->statusIds)
            ->pluck('name', 'id')
            ->each(function ($name, $id) use (&$statistic) {
                $statistic[$id] ??= ['name' => $name, 'count' => 0];
            });

        return $statistic->sortKeys();
    }

    private function calcDeliverySuccess(Collection $statistic, int $totalOrders): float
    {
        if ($totalOrders === 0) {
            return 0;
        }

        return round(($statistic[OrderStatus::DELIVERED]['count'] / $totalOrders) * 100, 2);
    }

    private function calcAvgPerHour(int $totalOrders, int $totalHours): float
    {
        if ($totalOrders === 0) {
            return 0;
        }

        return round($totalOrders / ($totalHours + 1), 2);
    }

    public function getOverallStats(DateRangeDTO $range, ?int $quadrant): array
    {
        $statistic = $this->buildOverallStatistic($range, $quadrant);
        $totalOrders = $statistic->sum('count');
        $activeClientsCount = $this->dashboard->getOverallActiveClientsCount($range, $quadrant);

        return [
            'total_orders' => $totalOrders,
            'active_clients' => $activeClientsCount,
            'delivery_success' => $this->calcDeliverySuccess($statistic, $totalOrders),
            'avg_orders_per_hr' => $this->calcAvgPerHour($totalOrders, $range->totalHours()),
            'statistic' => [
                'delivered' => $statistic[OrderStatus::DELIVERED]['count'] ?? 0,
                'canceled' => $statistic[OrderStatus::CANCEL]['count'] ?? 0,
                'returned_to_client' => $statistic[OrderStatus::CLIENT_RETURN_ACCEPTED]['count'] ?? 0,
                'return_to_client' => $statistic[OrderStatus::RETURN_TO_CLIENT]['count'] ?? 0,
                'cancel_request_accepted' => $statistic[OrderStatus::CANCEL_REQUEST_ACCEPTED]['count'] ?? 0,
            ],
        ];
    }

    private function buildOverallStatistic(DateRangeDTO $range, ?int $quadrant): Collection
    {
        return $this->dashboard->getOverallStatistic($range, $quadrant)->mapWithKeys(
            fn($item) => [
                $item->id => ['name' => $item->name, 'count' => $item->count],
            ],
        );
    }

    public function getClientsData(DateRangeDTO $range, ?int $clientId, int $pageSize): array
    {
        $clients = $this->dashboard->getClients($range, $clientId, $pageSize);
        $total_orders = $this->dashboard->getTotalOrders($range, $clientId);
        return compact('clients', 'total_orders');
    }

    public function getClientDashboardStats(DateRangeDTO $range, ?int $clientId): array
    {
        $statistic = $this->buildClientDashboardStatistic($range, $clientId);
        $totalOrders = $statistic->sum('count');

        return [
            'total_orders' => $totalOrders,
            'active_branches' => $this->dashboard->getActiveBranchCount($range, $clientId),
            'delivery_success' => $this->calcDeliverySuccess($statistic, $totalOrders),
            'avg_orders_per_hr' => $this->calcAvgPerHour($totalOrders, $range->totalHours()),
            'statistic' => [
                'delivered' => $statistic[OrderStatus::DELIVERED]['count'] ?? 0,
                'canceled' => $statistic[OrderStatus::CANCEL]['count'] ?? 0,
                'returned_to_client' => $statistic[OrderStatus::CLIENT_RETURN_ACCEPTED]['count'] ?? 0,
                'return_to_client' => $statistic[OrderStatus::RETURN_TO_CLIENT]['count'] ?? 0,
                'cancel_request_accepted' => $statistic[OrderStatus::CANCEL_REQUEST_ACCEPTED]['count'] ?? 0,
            ],
        ];
    }

    private function buildClientDashboardStatistic(DateRangeDTO $range, ?int $clientId): Collection
    {
        return $this->dashboard->getClientDashboardStatistic($range, $clientId)->mapWithKeys(
            fn($item) => [
                $item->id => ['name' => $item->name, 'count' => $item->count],
            ],
        );
    }

    public function getActiveClients(DateRangeDTO $range): Collection
    {
        return $this->dashboard->getActiveClients($range);
    }

    public function getClientShopsData(DateRangeDTO $range, ?int $clientId, ?int $shopId, int $pageSize): array
    {
        $shops = $this->dashboard->getClientShops(range: $range, clientId: $clientId, shopId: $shopId, pageSize: $pageSize);

        return [
            'client_shops' => $shops,
            'grand_totals' => $this->calcGrandTotals($shops),
        ];
    }

    private function calcGrandTotals(LengthAwarePaginator $shops): array
    {
        $totalOrders = $shops->sum('total_orders');
        $delivered = $shops->sum('delivered');

        return [
            'total_orders' => $totalOrders,
            'new_orders' => $shops->sum('new_orders'),
            'on_going_orders' => $shops->sum('on_going_orders'),
            'delivered' => $delivered,
            'unsuccessful_deliveries' => $shops->sum('unsuccessful_deliveries'),
            'success_rate' => $totalOrders > 0 ? round(($delivered / $totalOrders) * 100, 2) : 0,
            'avg_processing_time' => $this->calcAvgProcessingTime($shops),
        ];
    }

    private function calcAvgProcessingTime(LengthAwarePaginator $shops): string
    {
        $avgSeconds =
            $shops
                ->map(function ($shop) {
                    if (!$shop->average_consumed_time) {
                        return 0;
                    }

                    [$hours, $minutes, $seconds] = explode(':', $shop->average_consumed_time);

                    return $hours * 3600 + $minutes * 60 + $seconds;
                })
                ->average() ?? 0;

        return sprintf('%02d:%02d:%02d', floor($avgSeconds / 3600), floor(($avgSeconds % 3600) / 60), $avgSeconds % 60);
    }
}
