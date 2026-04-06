<?php

namespace App\Events;

use App\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderDeliveryFinish
{
    use Dispatchable, SerializesModels;

    public $order;
    public $user;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order = [
            'id' => $order->id,
        ];

        $this->user = auth()->user();
    }
}
