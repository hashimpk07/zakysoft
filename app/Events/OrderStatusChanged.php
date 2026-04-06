<?php

namespace App\Events;

use App\Cache\Cache;
use App\Order;
use App\OrderStatus;
use App\Ticket;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache as FacadesCache;
use Illuminate\Support\Facades\Log;

class OrderStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;
    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order = $order;

        FacadesCache::forget('order-eta-' . $order->id);

        if ($order->finished()) {
            \App\Jobs\MethodToQueue::dispatch(OrderDeliveryFinish::class, 'dispatch', [$order]);
        }

        if (in_array($order->status_id, [OrderStatus::CLIENT_RETURN_DECLINE])) {
            ClientDeclinedReturn::dispatch($order);
        }

        if (in_array($order->status_id, [OrderStatus::PENDING,OrderStatus::REQUEST_FOR_CANCEL, OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::RETURN_TO_CLIENT, OrderStatus::RESCHEDULED])) {
            if ($order->captain_id) {
                try {
                    $captain = \App\Captain::find($order->captain_id);
                    if ($captain && isset($captain->accessToken) && $captain->accessToken->fb_token) {
                        $metadata = \App\Reminder::getNotificationMetadata(\App\Reminder::FORCE_ASSIGN);
                        $lang = $captain->user->language ?? "en";

                        $isHold = Ticket::where('order_id', $order->id)
                                ->where('type', Ticket::TYPE_PENDING)
                                ->whereNull('closed_at')
                                ->where('created_by', '!=', $captain->user->id)
                                ->exists();


                        $titleKey = $isHold ? 'app/notifications.order_on_hold.title' : 'app/notifications.order_cancelled.title';
                        $bodyKey = $isHold ? 'app/notifications.order_on_hold.body' : 'app/notifications.order_cancelled.body';
                        $title = __($titleKey, [], $lang);
                        $body = __($bodyKey, [], $lang);

                        if ($title === $titleKey)
                            $title = "Order Cancelled";
                        if ($body === $bodyKey)
                            $body = "Your order has been cancelled or returned.";

                        $data = [
                            'priority' => 'High',
                            'content_available' => true,
                            'body' => $body,
                            'title' => $title,
                            'reminder_type' => \App\Reminder::FORCE_ASSIGN,
                            'id' => $order->id,
                            "sound" => $metadata['sound'] ?? "notification.wav",
                            "android_channel_id" => $metadata['android_channel_id'] ?? null,
                            "mutable_content" => true,
                        ];

                        \App\Jobs\FCMSend::dispatch($data, $captain->accessToken->fb_token, null, $captain->firebaseVersion());
                    }
                } catch (\Throwable $th) {
                    Log::error('Failed to send FCM notification for order cancellation/return', [
                        'order_id' => $order->id,
                        'captain_id' => $order->captain_id,
                        'error' => $th->getMessage()
                    ]);
                }
            }
        }

        if ($order->reDispatching()) {
            OrderReDispatching::dispatch($order);
        }

        if (in_array($order->status_id, [OrderStatus::ACCEPT])) {
            CaptainOrderAssigned::dispatch($order);
        }
    }

    public function broadcastWith()
    {
        return [
            'order' => [
                'id' => $this->order->id,
                'client' => $this->order->client->user->name,
                'shop' => $this->order->shop ? $this->order->shop->name : null,
                'zone' => $this->order->zone ? $this->order->zone->name : null,
            ],
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        $channels = [
            new PrivateChannel('orders'),
            new PrivateChannel('orders.client.' . $this->order->client_id)
        ];

        if ($this->order->captain && $this->order->captain->company) {
            $channels[] = new PrivateChannel('orders.3pl.' . $this->order->captain->company->id);
        }


        return $channels;
    }
}
