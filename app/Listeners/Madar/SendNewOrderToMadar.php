<?php

namespace App\Listeners\Madar;

use App\Events\NewOrder;
use App\Services\Integrations\MadarFleetService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendNewOrderToMadar implements ShouldQueue
{
    use InteractsWithQueue;

    protected $service;

    public function __construct(MadarFleetService $service)
    {
        $this->service = $service;
    }

    public function handle(NewOrder $event)
    {      
        if (isset($event->order['id'])) {
            $order = \App\Order::find($event->order['id']);
            if ($order) {
                $this->service->verifyOrder($order);
            }
        }
    }
}
