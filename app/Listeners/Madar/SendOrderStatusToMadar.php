<?php

namespace App\Listeners\Madar;

use App\Events\OrderStatusChanged;
use App\Services\Integrations\MadarFleetService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendOrderStatusToMadar implements ShouldQueue
{
    use InteractsWithQueue;

    protected $service;

    public function __construct(MadarFleetService $service)
    {
        $this->service = $service;
    }

    public function handle(OrderStatusChanged $event)
    {
        // OrderStatusChanged has $this->order = $order (Model object) based on Step 14 view_file.
        // public $order;
        // construct(Order $order) { $this->order = $order; }
        
        if ($event->order) {
            $this->service->updateOrder($event->order);
        }
    }
}
