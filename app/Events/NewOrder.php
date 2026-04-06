<?php

namespace App\Events;

use App\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewOrder implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;

    private $client_id;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $order = $order->load('client.user', 'shop', 'zone:id,name,region_id', 'progress', 'shop');

        $this->client_id = $order->client_id;

        $this->order = [
            'id' => $order->id,
            'order_id' => 'OR#' . sprintf('%03d', $order->id),
            'client' => $order->client->user->name,
            'client_order_id' => '#' . $order->client_order_id,
            'shop' => $order->shop ? $order->shop->name : null,
            'shopname' => $order->shop?->name ?? $order->shopname,
            'area' => $order->region_name ?? $order->shop?->region?->name,
            'zone' => $this->zone_name ?? $order->shop?->zone?->name,
            'amount' => $order->amount ?? 0.00 . ' SAR',
            'delivery_charge' => $order->delivery_charge . ' SAR',
            'order_date' => $order->created_at->format('Y-m-d'),
            'status' => [
                'name' => $order->progress?->name,
                'class' => $order->setClass(),
                'id' => $order->progress?->id,
            ],
            'captain' => null,
            'actions' => [
                'can_return' => $order->returnedToClient(),
                'can_cancel' => $order->clientCancelable(),
                'can_complain' => $order->complaintRaisable(),
                'open_complaint_count' => 0,
            ],
            'return_origin_reason' => null,
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel|array
     */
    public function broadcastOn()
    {
        return [new PrivateChannel('orders'), new PrivateChannel('orders.client.' . $this->client_id), ];
    }
}
