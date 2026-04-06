<?php

namespace App\Listeners;

use App\Actions\UpdateOrderReportAction;
use App\Events\OrderDeliveryFinish;
use App\Events\OrderReDispatching;
use App\Order;
use App\OrderReport;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class DeleteOrderReport implements ShouldQueue, ShouldHandleEventsAfterCommit
{
    use InteractsWithQueue;
    /**
     * Create the event listener.
     */
    // protected $updateOrderReportAction;
    public function __construct() 
    {}

    /**
     * Handle the event.
     *
     * @param  OrderReDispatching  $event
     * @return void
     */

    public function handle(OrderReDispatching $event): void
    {
        OrderReport::where('order_id', $event->order->id)->delete();
    }
}
