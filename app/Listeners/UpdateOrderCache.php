<?php
namespace App\Listeners;

use App\Order;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpdateOrderCache implements ShouldQueue
{
    public function handle($event)
    {
        $order = $event->order;
        
        if(! $order instanceof Order) {
            $order = (array) $order;
            $order = Order::find($order['id']);
        } else {
            $order = $order->fresh();
        }

        $order->updateCache();
    }
}