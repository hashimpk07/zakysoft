<?php

namespace App\Listeners;

use App\Events\OrderReDispatching;
use App\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class ReCalculateDeliveryCharge implements ShouldQueue
{
    public function handle(OrderReDispatching $event)
    {
        // find adjacent orders
        $order = Order::with('orderDeliveryCharge')->find($event->order['id']);

        $order_delivery_charge = $order->orderDeliveryCharge;

        if(!$order_delivery_charge) {
            return;
        }
        $order_delivery_charge->delete();
    }
}
