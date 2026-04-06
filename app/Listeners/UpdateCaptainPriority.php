<?php

namespace App\Listeners;

use App\Actions\AdjustCaptainPriority;
use App\Events\OrderDeliveryFinish;
use App\Order;

class UpdateCaptainPriority
{
    /**
     * @var AdjustCaptainPriority
     */
    protected $adjustCaptainPriority;

    /**
     * Create the event listener.
     *
     * @param AdjustCaptainPriority $adjustCaptainPriority
     * @return void
     */
    public function __construct(AdjustCaptainPriority $adjustCaptainPriority)
    {
        $this->adjustCaptainPriority = $adjustCaptainPriority;
    }

    /**
     * Handle the event.
     *
     * @param OrderDeliveryFinish $event
     * @return void
     */
    public function handle(OrderDeliveryFinish $event)
    {
       
        
        $orderId = $event->order['id'];

        $order = Order::with('captain')->find($orderId);

        if (!$order || !$order->captain_id) {
            return;
        }

        // Only proceed if order has a captain assigned
        if (!$order->captain_id) {
            return;
        }

        // Adjust the captain's priority based on completed orders vs target
        $captain = $order->captain;
        $this->adjustCaptainPriority->execute($captain);
    }
}