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

class FindDistanceStoreOrder implements ShouldQueue, ShouldHandleEventsAfterCommit
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
    // public function handle($event)
    // {
    //     // find adjacent orders
    //     $order = Order::with('shop')->find($event->order['id']);

    //     // if there are adjacent orders
    //     if(!$order || $order->location == null || (!isset($order->shop) || $order->shop->location == null)) {
    //         return;
    //     }

    //     // find distance between orders
    //     $to_order_position = new Position(...array_reverse(explode(',', $order->location)));
    //     $from_order_position = new Position(...array_reverse(explode(',', $order->shop->location)));

    //     $addresses = $order->addresses;

    //     if($order->reRoutedBeforeReachedDestination()) {
    //         $address = $addresses->shift();
    //         $to_order_position = new Position($address->longitude, $address->latitude);
    //     }

    //     $extra_endpoints = [];
    //     foreach ($addresses as $key => $address) {
    //         $extra_endpoints[] = new Position($address->longitude, $address->latitude);  
    //     }

    //     $airDirection = (new Map)->AirDirection($from_order_position, $to_order_position, $extra_endpoints);

    //     $clients = config('app.map_provider.google.clients');

    //     $provider = in_array($order->client_id, $clients) ? 'google' : 'mapbox';
    //     $roadDirection = (new Map('google'))->direction($from_order_position, $to_order_position, $extra_endpoints);

    //     $order->update([
    //         'shop_to_delivery_km' => $roadDirection->distance() / 1000,
    //     ]);

    //     OrderStore::updateOrCreate([
    //         'order_id' => $order->id,
    //         'client_shop_id' => $order->shop->id
    //     ], [
    //         'distance' => $airDirection->distance(),
    //         'traveling_time' => $airDirection->duration()
    //     ]);

    //     //\App\Jobs\FindDeliveryCharge::dispatch($order);
    // }

    public function handle($event)
    {
        // find adjacent orders
        $order = Order::with('shop')->find($event->order['id']);

        // if there are adjacent orders
        if (!$order || (!isset($order->shop) || $order->shop->location == null)) {
            Log::error("Can't find shop or shop location for order: {$order->id}, shop: {$order->shopname}");
            return;
        }

        if ($order->client_id == 287 && trim($order->location) == '10,10') {
            $addresses = $order->addresses;
            if ($addresses->isEmpty()) {
                return; // No addresses available
            }

            $firstAddress = $addresses->first();
            $to_order_position = new Position($firstAddress->longitude, $firstAddress->latitude);

            $extra_endpoints = [];
            foreach ($addresses->skip(1) as $address) {
                $extra_endpoints[] = new Position($address->longitude, $address->latitude);
            }
        } else {
            // Original logic for other clients
            if ($order->location == null) {
                return;
            }

            // find distance between orders
            $to_order_position = new Position(...array_reverse(explode(',', $order->location)));

            $addresses = $order->addresses;

            if ($order->reRoutedBeforeReachedDestination()) {
                // Check if there are any addresses before trying to shift
                if ($addresses->isNotEmpty()) {
                    $address = $addresses->shift();
                    $to_order_position = new Position($address->longitude, $address->latitude);
                } else {
                    // Log the issue for debugging
                    Log::warning("No addresses found for re-routed order: {$order->id}");
                    // Keep using the original location as fallback
                    // $to_order_position remains unchanged
                }
            }

            $extra_endpoints = [];
            foreach ($addresses as $key => $address) {
                $extra_endpoints[] = new Position($address->longitude, $address->latitude);
            }
        }

        $from_order_position = new Position(...array_reverse(explode(',', $order->shop->location)));

        $airDirection = (new Map)->AirDirection($from_order_position, $to_order_position, $extra_endpoints);

        $clients = config('app.map_provider.google.clients');

        $provider = in_array($order->client_id, $clients) ? 'google' : 'mapbox';
        $roadDirection = (new Map('google'))->direction($from_order_position, $to_order_position, $extra_endpoints);

        $order->update([
            'shop_to_delivery_km' => $roadDirection->distance() / 1000,
        ]);

        OrderStore::updateOrCreate([
            'order_id' => $order->id,
            'client_shop_id' => $order->shop->id
        ], [
            'distance' => $airDirection->distance(),
            'traveling_time' => $airDirection->duration()
        ]);

        //\App\Jobs\FindDeliveryCharge::dispatch($order);
    }
}
