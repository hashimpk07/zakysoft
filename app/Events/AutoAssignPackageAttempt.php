<?php

namespace App\Events;

use App\Captain;
use App\Package;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AutoAssignPackageAttempt
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $package = null;
    public $captains = [];

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Package $package, $captains = null)
    {
        $this->package = $package;
        $this->captains = $captains;
    }
}
