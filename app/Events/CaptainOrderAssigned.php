<?php

namespace App\Events;

use App\Order;
use Carbon\Carbon;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CaptainOrderAssigned implements ShouldBroadcast
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
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith()
    {
        $order = $this->order->load('client.user', 'shop', 'zone:id,name,region_id', 'progress', 'shop', 'captain.user');

        [$start_time, $end_time] = $order->remainingTime();

        return [
            'order' => [
                'id' => $order->id,
                'order_id' => $order->id,
                'client_order_id' => $order->client_order_id,
                'shop_name' => $order->shop?->name ?? '',
                'area' => $order->shop?->region?->name ?? '',
                'zone' => $order->shop?->zone?->name ?? '',
                'type' => $order->delivery_type,
                'status' => $order->progress?->name ?? '',
                'client_id' => $order->client_id,
                'client_name' => $order->client?->user?->name ?? '',
                'amount' => $order->amount,
                'updated_at' => $order->delivery_date?->format('d-m-Y h:i:s a'),
                'assigned_captain' => $order->captain?->user?->name ?? '',
                'timer' => [
                    'start_time' => $start_time ? Carbon::parse($start_time)->toIso8601String() : null,
                    'end_time' => $end_time ? Carbon::parse($end_time)->toIso8601String() : null,
                ],
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
        return [new PrivateChannel('orders.' . $this->order->captain_id), new PrivateChannel('orders.3pl.' . $this->order->captain->company->id)];
    }
}
