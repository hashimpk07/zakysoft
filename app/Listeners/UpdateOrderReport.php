<?php

namespace App\Listeners;

use App\Actions\UpdateOrderReportAction;
use App\Events\OrderDeliveryFinish;
use App\Order;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class UpdateOrderReport implements ShouldQueue, ShouldHandleEventsAfterCommit
{
    use InteractsWithQueue;
    /**
     * Create the event listener.
     */
    // protected $updateOrderReportAction;
    public function __construct(
        protected UpdateOrderReportAction $updateOrderReportAction
    ) 
    {}

    /**
     * Handle the event.
     *
     * @param  OrderDeliveryFinish  $event
     * @return void
     */

    public function handle(OrderDeliveryFinish $event): void
    {
        $this->updateOrderReportAction->execute(Order::find($event->order['id']));
    }
}
