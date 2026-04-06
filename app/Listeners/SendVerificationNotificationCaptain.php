<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Notifications\VerifyOrderBeforeStartRide;
use App\Order;
use App\OrderStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;

class SendVerificationNotificationCaptain implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  OrderStatusChanged  $event
     * @return void
     */

    public function handle(OrderStatusChanged $event): void
    {
        $order =  Order::find($event->order['id']);
        $verification_needed_clients = config('app.verification_needed_before_start_ride');
        if(in_array($order->client_id, $verification_needed_clients) && $order->status_id == OrderStatus::ACCEPT && $order->captain) {
            Notification::route('sms_api', $order->captain->phone_number)->notify(new VerifyOrderBeforeStartRide($order));
        }
    }
}
