<?php

namespace App\Events;

use App\Captain;
use App\Order;
use App\Package;
use App\PackageRejectionReason;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AutoAssignPackageAvailable implements ShouldBroadcast
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
            'package' => [
                'package_id' => $this->package->id,
                'no_of_orders_in_package' => $this->package->orders->count(),
                'rejection_reasons' => PackageRejectionReason::all()
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
        return new PrivateChannel('orders.'. $this->captain->id);
    }
}
