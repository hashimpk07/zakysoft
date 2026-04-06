<?php

namespace App\Listeners;

use App\Actions\UpdateCaptainReportAction;
use App\Events\OrderDeliveryFinish;
use App\Order;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpdateCaptainReport implements ShouldQueue, ShouldBeUnique, ShouldHandleEventsAfterCommit
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    protected $order;
    protected $updateCaptainReportAction;
    /**
     * Create a new job instance.
     */
    public function __construct(Order $order, UpdateCaptainReportAction $updateCaptainReportAction)
    {
        $this->order = $order;
        $this->updateCaptainReportAction = $updateCaptainReportAction;
    }

    /**
     * Handle the event.
     *
     * @param  OrderDeliveryFinish  $event
     * @return void
     */

    public function handle(OrderDeliveryFinish $event)
    {   
        $order = Order::find($event->order['id']);
        $this->updateCaptainReportAction->execute($order);
    }
}
