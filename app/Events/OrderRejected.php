<?php
namespace App\Events;

use App\Order;
use App\OrderStatus;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderRejected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order)
    {}

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return [
            "order" => [
              'id' => $this->order->id,
              'client_order_id' => $this->order->client_order_id,
              'client' => $this->order->client->user->name,
              'shop' => $this->order->shop->name     
            ]
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
      $log = $this->order->logs()->where('status_id', OrderStatus::WAITING_FOR_ACCEPTING)->latest()->first();
      return new PrivateChannel('App.User.' . $log->created_by);
    }
}