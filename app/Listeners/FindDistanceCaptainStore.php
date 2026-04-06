<?php

namespace App\Listeners;

use App\Events\NewOrder;
use App\Events\OrderAddressChanged;
use App\Events\OrderStatusChanged;
use App\Events\SellaNewOrder;
use App\Http\Controllers\Api\ThirdPartyOrdersController;
use App\Order;
use App\OrderOrdersDistance;
use App\OrderStatus;
use App\OrderStore;
use App\Services\Adapters\Clients\Sella;
use App\Services\Map;
use App\Services\Position;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class FindDistanceCaptainStore implements ShouldQueue, ShouldHandleEventsAfterCommit
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
     * @param  CaptainOrderAssigned  $event
     * @return void
     */
    public function handle($event)
    {
        // find adjacent orders
        $order = Order::with('shop', 'captain.location')->find($event->order['id']);
        
        // if there are adjacent orders
        if(!$order || !$order->captain || !$order->captain->location || (!isset($order->shop) || $order->shop->location == null)) {
            return;
        }

        $location = $order->captain->location;
        // find distance between orders
        $captain_location = new Position($location->longitude, $location->latitude);
        $from_order_position = new Position(...array_reverse(explode(',', $order->shop->location)));

        $roadDirection = (new Map)->direction($from_order_position, $captain_location);
        
        $order->update([
            'captain_to_shop_km' => $roadDirection->distance() / 1000,
        ]);
    }
}
