<?php

namespace App\Listeners;

use App\Actions\ReCalculateCaptainReportAction;
use App\Events\OrderReDispatching;
use App\Order;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

class ReCalculateCaptainReport implements ShouldQueue, ShouldBeUnique, ShouldHandleEventsAfterCommit
{
    /**
     * Create a new job instance.
     */
    public function __construct(public Order $order, public ReCalculateCaptainReportAction $reCalculateCaptainReportAction)
    {
    }

    /**
     * Handle the event.
     *
     * @param  OrderReDispatching  $event
     * @return void
     */

    public function handle(OrderReDispatching $event)
    {
        $this->reCalculateCaptainReportAction->execute($event->order);

    }
}
