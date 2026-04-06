<?php

namespace App\Services\General\Orders;

use App\Http\Requests\General\Orders\ClientShopOrderRequest;
use App\Http\Requests\General\Orders\OrderListRequest;
use App\Http\Requests\General\Orders\ShopOrderRequest;
use App\Interfaces\General\OrderInterface;
use App\Order;
use App\OrderStatus;
use Facades\App\Content;
use Illuminate\Pagination\LengthAwarePaginator;

final class OrderService
{

    public function __construct(protected readonly OrderInterface $orderInterface)
    {
    }

    public function getOrders(OrderListRequest $request): LengthAwarePaginator
    {
        return $this->orderInterface->getOrders(request: $request);
    }

    public function getOrderCounts(OrderListRequest $request): array
    {
        $counts = $this->orderInterface->getOrderCounts($request);

        return [
            'new_orders_count' => $counts['new_orders'],
            'assign_attempts_orders_count' => $counts['assign_attempts'],
            'on_going_orders_count' => $counts['on_going'],
            'complaints_orders_count' => $counts['complaints'],
            'client_return_orders_count' => $counts['client_return'],
            'request_for_cancel_orders_count' => $counts['request_for_cancel'],
            'total_active_orders' => array_sum($counts),
            'pending_chat_count' => Content::pendingChatCount(),
            'tickets_count' => Content::ticketChatCount(),
            'client_chat_count' => Content::clientChatCount()
        ];
    }

    public function getScheduleOrders(OrderListRequest $request): LengthAwarePaginator
    {
        return $this->orderInterface->getScheduledOrders(request: $request);
    }

    public function getClientShopOrders(ClientShopOrderRequest $request): LengthAwarePaginator
    {
        return $this->orderInterface->getClientShopOrders($request);
    }

    public function getShopOrders(int $shopId, ShopOrderRequest $request): LengthAwarePaginator
    {
        return $this->orderInterface->getShopOrders($shopId, $request);
    }

    public function getPaginatedLiveOrders(array $filters, int $perPage = 10): LengthAwarePaginator
    {
        $orders = $this->orderInterface->getLiveOrders($filters, $perPage);
        $orders->setCollection(
            $orders->getCollection()->map(fn($order) => $order->getAttributes())
        );

        return $orders;
    }

    public function getLiveStatistics(): array
    {
        return [
            'new_orders_count' => $this->orderInterface->getLiveOrderCount([
                'auto_assignable' => 'false',
                'status_id' => OrderStatus::NEW_ORDER,
            ]),

            'assign_attempts_orders_count' => $this->orderInterface->getLiveOrderCount([
                'auto_assignable' => 'true',
                'status_id' => [
                    OrderStatus::NEW_ORDER,
                    OrderStatus::ORDER_PACKAGE,
                    OrderStatus::ASSIGN_ATTEMPTS,
                ],
            ]),

            'on_going_orders_count' => $this->orderInterface->getLiveOrderCount([
                'status_id' => OrderStatus::ON_GOING_ORDER,
            ]),

            'complaints_orders_count' => $this->orderInterface->getLiveOrderCount([
                'has_open_complaint' => 'true',
            ]),

            'ticket_raised_orders_count' => $this->orderInterface->getLiveOrderCount([
                'status_id' => OrderStatus::TICKET_RAISED,
            ]),

            'pending_orders_count' => $this->orderInterface->getLiveOrderCount([
                'status_id' => OrderStatus::PENDING,
            ]),

            'client_return_orders_count' => $this->orderInterface->getLiveOrderCount([
                'status_id' => OrderStatus::RETURN_TO_CLIENT,
            ]),

            'request_for_cancel_orders_count' => $this->orderInterface->getLiveOrderCount([
                'status_id' => OrderStatus::REQUEST_FOR_CANCEL,
            ]),

            'scheduled_orders_count' => $this->orderInterface->getLiveOrderCount([
                'delivery_type' => Order::DELIVERY_TYPE_SCHEDULE,
            ]),
        ];
    }

    public function getPendingOrders(array $filters): LengthAwarePaginator
    {
        return $this->orderInterface->getPendingOrders($filters);
    }
}