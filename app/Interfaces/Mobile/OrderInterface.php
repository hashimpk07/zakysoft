<?php

namespace App\Interfaces\Mobile;

use App\Captain;
use App\Filter\OrderFilter;
use App\Order;
use App\Package;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface OrderInterface
{
    public function getOrderStatistics($fromDate, $toDate): array;
    public function getCaptainsCount(): array;
    public function getOrders(OrderFilter $request, int $perPage):LengthAwarePaginator;

    public function getPackageForAcceptableOrders(Request $request, int $captainId);

    public function getAcceptableOrders(int $captainId);

    public function getPackageRejectReasons();

    public function getEarningStatistics(Captain $captain, Carbon $from, Carbon $to);
    public function getEarningStatisticsList(Captain $captain, Carbon $from, Carbon $to, int $perPage): ?LengthAwarePaginator;

    public function getCommissionTransactionList(Captain $captain, int $perPage): ?LengthAwarePaginator;

    public function updateOrder(Order $order, array $data): Order;

    public function findPackageId(int $packageId);

    public function updateLatestPackageDeliveryRequest(int $packageId, int $captainId);

    public function updateOrderStatus($package, $captain);
    public function updatePackage(Package $package , array $data);
    public function getPackageDeliveryRequest(int $packageId, int $captainId);
    public function getDirectOrders(int $packageId);
    public function getRejectionReasonText(int $reasonId);
    public function markOrderDeclined(array $packageIds, ?int $reasonId);
    public function getCaptainOrders(int $captainId, ?int $status): Collection;
}
