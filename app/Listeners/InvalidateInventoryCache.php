<?php

namespace App\Listeners;

use App\Events\StockMovementRecorded;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use App\Jobs\LogStockMovementJob;

class InvalidateInventoryCache
{
   use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(StockMovementRecorded $event): void
    {
        $productId = $event->stockMovement->product_id;
        $warehouseId = $event->stockMovement->warehouse_id;

        Cache::forget("inventory_report_" . md5($event->stockMovement->product_id . '_' . $event->stockMovement->warehouse_id));
        dispatch(new LogStockMovementJob($event->stockMovement));

    }
}
