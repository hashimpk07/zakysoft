<?php

namespace App\Listeners;

use App\Events\SallaNewOrder;
use App\Services\Adapters\Clients\Salla;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class ProcessSallaNewOrder implements ShouldQueue 
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  SallaNewOrder  $event
     * @return void
     */
    public function handle(SallaNewOrder $event)
    {
        Salla::getInstance()->process($event->order);
    }
}
