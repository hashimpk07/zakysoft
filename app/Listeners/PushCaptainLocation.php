<?php

namespace App\Listeners;

use App\Events\CaptainLocationChanged;
use App\ThirdPartyOrderStatusPushLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PushCaptainLocation implements ShouldQueue
{

    /**
     * The name of the queue the job should be sent to.
     *
     * @var string|null
     */
    public $queue = 'location-push';

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
     * @param  CaptainLocationChanged  $event
     * @return void
     */
    public function handle(CaptainLocationChanged $event)
    {
        $location = $event->location;
        $captain = $location->captain;
        $orders = $captain->currentOrder;

        if(Cache::has('captain_location_'.$captain->id)) {
            return;
        }

        Cache::put('captain_location_'.$captain->id, $location, 30);

        foreach ($orders as $key => $order) {
            $client = $order->client;
            if($source = $client->clientSource) {
                if(array_key_exists($source->driver, config('partners.drivers')) && $driver = config('partners.drivers')[$source->driver]) {
                    $response = $driver::getInstance()->pushCaptainLocation($captain, $order);
                    if($response instanceof Response) {
                        $this->record($order, json_encode($driver::getInstance()->pushCaptainLocationData($captain, $order)), $response->status(), json_encode($response->json()));
                    } 
                }
            }
        }
    }

    public function record($order, $payload, $status = 0, $response = '[]') {
        ThirdPartyOrderStatusPushLog::create([
            'order_id' => $order->id,
            'payload' => $payload,
            'status' => $status,
            'response' => $response
        ]);
    }
}
