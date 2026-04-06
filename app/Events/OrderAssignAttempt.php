<?php

namespace App\Events;

use App\Captain;
use App\Package;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderAssignAttempt implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(public Package $package, public Captain $captain)
    {
    }


    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return [
            'order' => [
                'id' => $this->package->id,
                'orders_count' => $this->package->directOrders->count(),
                "accept_before" => now()->diffInSeconds(now()->addMinutes(\App\Captain::CAPTAIN_ACCEPTING_TIME_IN_MINUTES), false),
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
        return new PrivateChannel('orders.' . $this->order->captain_id);
    }
}