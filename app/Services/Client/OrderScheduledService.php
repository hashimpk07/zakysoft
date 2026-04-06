<?php

namespace App\Services\Client;

use App\Repositories\Client\OrderScheduledRepository;
use App\Filter\OrderFilter;

class OrderScheduledService
{
    protected $orders;

    public function __construct(OrderScheduledRepository $orders)
    {
        $this->orders = $orders;
    }

    public function getScheduledOrdersData(OrderFilter $request)
    {
        return [
            'orders' => $this->orders->getScheduledOrders($request),

        ];
    }
}
