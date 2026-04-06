<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\ClientShop;

class UpdateClientShop
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $client_shop;
    /**
     * Create a new event instance.
     */
    public function __construct(ClientShop $client_shop)
    {
        $this->client_shop = $client_shop;
    }


}
