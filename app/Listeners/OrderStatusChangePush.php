<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Events\SellaNewOrder;
use App\Http\Controllers\Api\ThirdPartyOrdersController;
use App\Order;
use App\Services\Adapters\Clients\Sella;
use App\ThirdPartyOrderStatusPushLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class OrderStatusChangePush implements ShouldQueue
{
    use InteractsWithQueue;


    /**
     * The number of times the queued listener may be attempted.
     *
     * @var int
     */
    public $tries = 3;

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
     * @param  OrderStatusChanged  $event
     * @return void
     */
    public function handle(OrderStatusChanged $event)
    {
        $order = $event->order;
        $client = $order->client;
        if ($source = $client->clientSource) {
            if (array_key_exists($source->driver, config('partners.drivers')) && $driverClass = config('partners.drivers')[$source->driver]) {
                $driver = $driverClass::getInstance();
                
                // --- NEW LOGIC: Check and push missing previous statuses ---
                $this->checkAndPushPreviousStatuses($order, $driver);

                $response = $driver->push($order);
                if ($response instanceof Response) {
                    $this->record($order, json_encode($driver->updateStatusPushData($order)), $response->status(), json_encode($response->json()));
                }
            }
        }
    }

    protected function checkAndPushPreviousStatuses($order, $driver)
    {
        if (!method_exists($driver, 'getPushStatuses')) {
            return;
        }

        $pushStatuses = $driver->getPushStatuses();
        $currentIndex = array_search($order->status_id, $pushStatuses);
        
        if ($currentIndex === false || $currentIndex === 0) {
            return; // It's the first status or not in push_statuses array
        }

        $pushLogs = ThirdPartyOrderStatusPushLog::where('order_id', $order->id)->get();
        $passedStatuses = \App\OrderLog::where('order_id', $order->id)->pluck('status_id')->toArray();

        for ($i = 0; $i < $currentIndex; $i++) {
            $prevStatus = $pushStatuses[$i];
            
            if (!in_array($prevStatus, $passedStatuses)) {
                continue; // Skip if the order never internally reached this status
            }

            $signature = $this->getStatusSignature($order, $driver, $prevStatus);
            if ($signature) {
                $isPushed = $this->isStatusPushed($pushLogs, $signature);
                if (!$isPushed) {
                    // Push it!
                    $currentStatus = $order->status_id;
                    $order->status_id = $prevStatus;
                    try {
                        $response = $driver->push($order);
                        if ($response instanceof Response) {
                            $this->record($order, json_encode($driver->updateStatusPushData($order)), $response->status(), json_encode($response->json()));
                        }
                    } catch (\Exception $e) {
                        Log::error('Repush failed for status ' . $prevStatus . ': ' . $e->getMessage());
                    }
                    $order->status_id = $currentStatus; // restore order status
                }
            }
        }
    }

    protected function getStatusSignature($order, $driver, $status_id)
    {
        $original = $order->status_id;
        $order->status_id = $status_id;
        $data = $driver->updateStatusPushData($order);
        $order->status_id = $original;

        if (!is_array($data)) return null;

        $possible_keys = ['status', 'statusCode', 'delivery_status', 'delivery_state', 'status_id', 'status_name'];
        foreach ($possible_keys as $key) {
             if (isset($data[$key])) {
                 return [$key => $data[$key]];
             }
        }
        return null;
    }

    protected function isStatusPushed($pushLogs, $signature)
    {
        $key = array_key_first($signature);
        $val = $signature[$key];

        foreach ($pushLogs as $log) {
            $payload = json_decode($log->payload, true);
            if (is_array($payload) && isset($payload[$key]) && (string)$payload[$key] === (string)$val) {
                return true;
            }
        }
        return false;
    }

    public function record($order, $payload, $status = 0, $response = '[]')
    {
        ThirdPartyOrderStatusPushLog::create([
            'order_id' => $order->id,
            'payload' => $payload,
            'status' => $status,
            'response' => $response
        ]);
    }

    public function failed(OrderStatusChanged $event, $exception)
    {
        Log::error('All webhook delivery attempts failed', [
            'order_id' => $event->order->id,
            'exception' => $exception->getMessage()
        ]);
    }
}
