<?php

namespace App\Listeners;

use App\Events\OrderDeliveryFinish;
use App\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CloseTicket implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
    }

    /**
     * Handle the event.
     */
    public function handle(OrderDeliveryFinish $event): void
    {
        $order = Order::find($event->order['id']);

        $order->openTickets->each(function($ticket) {
            $ticket->closed_at = now();
            $ticket->save();
        });
    }
}
