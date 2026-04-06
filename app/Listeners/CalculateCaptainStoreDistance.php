<?php

namespace App\Listeners;

use App\Captain;
use App\CaptainLocationLog;
use App\CaptainStore;
use App\ClientShop;
use App\Events\NewOrder;
use App\Order;
use App\Services\Map;
use App\Services\Position;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CalculateCaptainStoreDistance implements ShouldQueue
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
        $order = Order::with('shop')->find($event->order['id']);
        $client_shop = ClientShop::with('region')->find($order->shopname);

        if (!$client_shop || !$client_shop->region) {
            Log::info("Can't find shop or shop region for order: {$order->id}, shop: {$order->shopname}");
            return;
        }

        $captains = Captain::with('location')
            ->whereHas('regions', function ($query) use ($client_shop) {
                $query->where('regions.id', $client_shop->region->id);
            })
            ->online()
            ->get(['id']);

        if ($captains->isEmpty()) {
            return;
        }

        $this->batchProcessCaptains($client_shop, $captains);

    }


    private function batchProcessCaptains($shop, $captains)
    {
        $captain_ids = $captains->pluck('id')->toArray();

        $captainsWithLocation = Captain::with('location')
            ->whereIn('id', $captain_ids)
            ->get();

        $batch_data = [];

        foreach ($captainsWithLocation as $captain) {
            if ($captain->location instanceof CaptainLocationLog) {
                $distance_data = $this->calculateDistance($shop, $captain);
                if ($distance_data) {
                    $batch_data[] = [
                        'captain_id' => $captain->id,
                        'client_shop_id' => $shop->id,
                        'distance' => $distance_data['distance'],
                        'traveling_time' => $distance_data['traveling_time'],
                        'updated_at' => now(),
                        'created_at' => now()
                    ];
                }
            }
        }

        if (!empty($batch_data)) {
            CaptainStore::upsert($batch_data, ['captain_id', 'client_shop_id'], ['distance', 'traveling_time', 'updated_at']);
        }
    }


    private function calculateDistance($shop, $captain)
    {
        try {
            $storePosition = new Position(...array_reverse(explode(',', $shop->location)));
            $captain_location = new Position($captain->location->longitude, $captain->location->latitude);
            $direction = (new Map())->AirDirection($captain_location, $storePosition);

            return [
                'distance' => $direction->distance(),
                'traveling_time' => $direction->duration()
            ];
        } catch (\Exception $e) {
            Log::warning("Error calculating distance for captain {$captain->id} and shop {$shop->id}: " . $e->getMessage());
            return null;
        }
    }


}