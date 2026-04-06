<?php

namespace App\Listeners;

use App\CancellationReason;
use App\CancellationReasonDeliveryOrderReturnCharge;
use App\CaptainThirdPartyLogistic;
use App\DeliveryCancellationCharge;
use App\DeliveryChargeRulePrice;
use App\DeliveryChargeRulePriceZone;
use App\Events\OrderStatusChanged;
use App\Order;
use App\OrderDeliveryCharge;
use App\OrderLog;
use App\OrderPendingReason;
use App\OrderStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Log;

class UpdateClientDeliveryCharge implements ShouldQueue
{
    /**
     * Create the event listener.
     *
     * @return void
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

    public function handle(OrderStatusChanged $event)
    {
       
        $order =  Order::with('shop','orderDeliveryCharge')->find($event->order['id']);
        if($order){
            if($order->status_id == OrderStatus::NEW_ORDER || $order->status_id == OrderStatus::DELIVERED ){
                \App\Jobs\FindDeliveryCharge::dispatch($order);

            }
            
            if($order->status_id == OrderStatus::CANCEL ||$order->status_id == OrderStatus::CANCEL_REQUEST_ACCEPTED || $order->status_id == OrderStatus::CLIENT_RETURN_ACCEPTED  ){
                \App\Jobs\FindDeliveryCharge::dispatch($order);

                \App\Jobs\UpdateCancelReturnDeliveryCharge::dispatch($order)->delay(now()->addSeconds(3));
               
            }

            if($order->status_id == OrderStatus::DELIVERED || $order->status_id == OrderStatus::CANCEL_REQUEST_ACCEPTED|| $order->status_id == OrderStatus::CLIENT_RETURN_ACCEPTED){
                if(CaptainThirdPartyLogistic::where('captain_id',$order->captain_id)->exists())
                {
                    \App\Jobs\UpdateThirdPartyOrderBalance::dispatch($order)->delay(now()->addSeconds(3));
                }
            }
     
        }
       
    }
   
}
