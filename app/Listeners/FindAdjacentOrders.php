<?php

namespace App\Listeners;

use App\Events\NewOrder;
use App\Order;
use App\OrderOrdersDistance;
use App\OrderStatus;
use App\Services\Map;
use App\Services\Position;
use App\Vat;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class FindAdjacentOrders implements ShouldQueue, ShouldHandleEventsAfterCommit
{
    use InteractsWithQueue;
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
     * @param  NewOrder  $event
     * @return void
     */
    public function handle(NewOrder $event)
    {
        // find adjacent orders
        $order = Order::with('shop','client')->find($event->order['id']);

        if(!$order || $order->location == null || $order->status_id != OrderStatus::NEW_ORDER ) {
            return;
        }

        // orders from same zone and same shop
        $adjacent_orders = Order::select('orders.*')->where([
                ['orders.shopname', $order->shopname],
                ['orders.zone_id', $order->zone_id],
                ['orders.id', '!=', $order->id],
            ])->autoAssignable()->toDispatch()->get();

        // if there are adjacent orders
        if($adjacent_orders->isEmpty()) {
            return;
        }

        // find distance between orders
        foreach ($adjacent_orders as $key => $adjacent_order) {
            if($adjacent_order->location == null) {
                continue;
            }
            $from_order_position = new Position(...array_reverse(explode(',', $order->location)));
            $to_order_position = new Position(...array_reverse(explode(',', $adjacent_order->location)));
            $direction = (new Map)->AirDirection($from_order_position, $to_order_position);
    
            OrderOrdersDistance::updateOrCreate([
                'from_order_id' => $order->id,
                'to_order_id' => $adjacent_order->id
            ], [
                'distance' => $direction->distance(),
                'traveling_time' => $direction->duration()
            ]);
        }
    }
}
