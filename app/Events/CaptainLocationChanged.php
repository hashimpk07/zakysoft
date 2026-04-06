<?php

namespace App\Events;

use App\Captain;
use App\CaptainLocationLog;
use App\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CaptainLocationChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Determine if the model should be deleted when it is missing.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(public CaptainLocationLog $location)
    {}

    public function broadcastOn()
    {
        return [new PrivateChannel('captains'), new PrivateChannel('captain.' . $this->location->captain_id)];
    }
}
