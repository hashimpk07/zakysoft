<?php

namespace App\Listeners;

use App\Events\OrderDeliveryFinish;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

use App\Jobs\NotifyOdooOfNewCreateOrderJob;

class NotifyOdooOfNewCreateOrder implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(OrderDeliveryFinish $event): void
    {
        Log::info('Listener executed at: ' . now());
        Log::info('NotifyOdooOfNewCreateOrder ::handle:: -------'.json_encode($event)); 
 
        // NotifyOdooOfNewCreateOrderJob::dispatch($event)->delay(now()->addHours(2));
        // NotifyOdooOfNewCreateOrderJob::dispatch($event)->delay(now()->addMinutes(1));
        NotifyOdooOfNewCreateOrderJob::dispatch($event);
        
        Log::info('Dispatched NotifyOdooOfNewCreateOrderJob with 2 hour delay at: ' . now());
       
    }
}
