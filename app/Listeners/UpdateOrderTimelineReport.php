<?php

namespace App\Listeners;

use App\Actions\UpdateOrderTimelineReportAction;
use App\Events\OrderDeliveryFinish;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class UpdateOrderTimelineReport implements ShouldQueue, ShouldBeUnique, ShouldHandleEventsAfterCommit
{
    use InteractsWithQueue;

    // public $delay = 15;
    /**
     * Create the event listener.
     */

    public function __construct(protected UpdateOrderTimelineReportAction $updateOrderTimelineReportAction)
    {
    }

    /**
     * Handle the event.
     *
     * @param  OrderDeliveryFinish  $event
     * @return void
     */

    public function handle(OrderDeliveryFinish $event): void
    {
        $this->updateOrderTimelineReportAction->execute($event->order);
    }
}
