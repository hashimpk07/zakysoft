<?php

namespace App\Observers;

use App\Order;
use App\Services\StreamlineCacheService;

class OrderObserver
{
    protected $cacheService;

    public function __construct(StreamlineCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Handle the Order "saved" event.
     *
     * @param  \App\Order  $order
     * @return void
     */
    public function saved(Order $order)
    {
        $this->cacheService->updateOrder($order);
    }

    /**
     * Handle the Order "deleted" event.
     *
     * @param  \App\Order  $order
     * @return void
     */
    public function deleted(Order $order)
    {
        $this->cacheService->removeOrder($order);
    }
}
