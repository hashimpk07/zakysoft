<?php

namespace App\Listeners;

use App\Events\AutoAssignPackageBound;
use App\Events\OrderStatusChanged;
use App\Order;
use App\OrderStatus;
use App\Services\OrderStatusLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class LogAutoAssignPackageBound implements ShouldQueue
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
     * @param  AutoAssignPackageBound  $event
     * @return void
     */
    public function handle(AutoAssignPackageBound $event)
    {
        $package = $event->package;

        $orders = $package->directOrders;
        $note = "#{$orders->pluck('id')->join(',# ')}";
        foreach ($orders as $key => $order) {
            $order = $order->fresh();
            if (! $order) {
                continue;
            }

            if (
                in_array($order->status_id, [
                    OrderStatus::NEW_ORDER,
                    OrderStatus::ORDER_PACKAGE,
                    OrderStatus::ASSIGN_ATTEMPTS,
                    OrderStatus::RESCHEDULED
                ])
            ) {
                $order->status_id = OrderStatus::ORDER_PACKAGE;
                $order->save();

                OrderStatusChanged::dispatch($order);
                (new OrderStatusLog)->log(OrderStatus::ORDER_PACKAGE, null, $order->id, null, $note, null, config('app.system_user'));
            }
            // $order->status_id = OrderStatus::ORDER_PACKAGE;
            // $order->save();

            // OrderStatusChanged::dispatch($order);
            // (new OrderStatusLog)->log(OrderStatus::ORDER_PACKAGE, null, $order->id, null, $note, null, config('app.system_user'));
        }
    }
}
