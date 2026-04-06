<?php

namespace App\Interfaces;

use App\Filter\OrderFilter;
use App\Order;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface OrderInterface
{
    public function createOrder($data);
    public function addNotesToOrder($order, $note, $user);
    public function getOnlineCaptains(array $filters, int $perPage);
    public function updateOrderStatus(Order $order, int $statusId, $loggedUser);
    public function handleReschedule(Order $order, int &$statusId, Request $request, ?int $reasonId = null): bool;
    public function getOrdersWithCaptain(array $orderIds): Collection;
    public function getScheduledOrders(OrderFilter $filter, int $perPage): LengthAwarePaginator;
    public function getConsolidatedOrders(array $request, int $perPage, $user): LengthAwarePaginator;
    public function getSingleConsolidatedOrder(int $shopId, array $filters, int $perPage): LengthAwarePaginator;
}
