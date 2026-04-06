<?php

namespace App\Interfaces;

use App\User;
use App\Order;
use App\GeneralExport;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

interface ClientInterface
{
    public function getClientOrdersQuery(int $clientId, array $shopIds);
    public function getStatusSummary($query);
    public function countByStatus($query, ?int $statusId = null, ?string $startDate = null, ?string $endDate = null);
    public function countNewOrders(User $user, Request $request);
    public function countAssignableAttempts(User $user, Request $request);
    public function countOngoing(User $user, Request $request);
    public function countByStatusList(User $user, Request $request, array $statusIds);
    public function salesReportQuery(int $clientId, bool $onlyBaseTable = false);
    public function getOrders(int $clientId, Request $request, int $page = 50): LengthAwarePaginator;
    public function getOrderById(array $shopIds, int $orderId);
    public function getClientReports(array $filters, int $perPage): LengthAwarePaginator;
    public function salesReportDataQuery(int $clientId, bool $onlyBaseTable = false, $fromDate = null, $toDate = null, $search = null);
    public function getOrderStatusGraphCount($query);
    public function getDeliveredOrdersGroupedByMonth(int $clientId, array $shopIds, string $startDate, string $endDate);

    public function findBelongsToUser(User $user, int $orderId): ?Order;
    public function updateStatus(Order $order, int $statusId): bool;

    public function getClientsAndShops(User $user);
    public function exportClientOrderCreate(array $data): GeneralExport;
  
}
