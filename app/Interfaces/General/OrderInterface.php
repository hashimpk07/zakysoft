<?php

namespace App\Interfaces\General;

use App\Http\Requests\General\Orders\ClientShopOrderRequest;
use App\Http\Requests\General\Orders\OrderListRequest;
use App\Http\Requests\General\Orders\ShopOrderRequest;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

interface OrderInterface{
    public function getOrders(OrderListRequest $request): LengthAwarePaginator;
    public function getOrderCounts(OrderListRequest $request): array;
    public function getScheduledOrders(OrderListRequest $request): LengthAwarePaginator;
    public function getClientShopOrders(ClientShopOrderRequest $request): LengthAwarePaginator;
    public function getShopOrders(int $shopId, ShopOrderRequest $request): LengthAwarePaginator;

    public function getLiveOrders(array $request, $perPage =10): LengthAwarePaginator;
    public function getLiveOrderCount(array $filters);
    public function getPendingOrders(array $filters): LengthAwarePaginator;
}