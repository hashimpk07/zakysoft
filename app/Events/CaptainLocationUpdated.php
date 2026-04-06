<?php

namespace App\Events;

use App\Captain;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CaptainLocationUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The captain whose location was updated.
     *
     * @var \App\Captain
     */
    public $captain;

    /**
     * Create a new event instance.
     *
     * @param  \App\Captain  $captain
     * @return void
     */
    public function __construct(Captain $captain)
    {
        $this->captain = $captain;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('captain.location.' . $this->captain->id);
    }
}