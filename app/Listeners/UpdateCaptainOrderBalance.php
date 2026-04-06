<?php

namespace App\Listeners;

use App\Actions\UpdateCaptainReportBalanceAction;
use App\CaptainOrderPayment;
use App\Events\OrderDeliveryFinish;
use App\Order;
use App\OrderPayment;
use App\OrderStatus;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpdateCaptainOrderBalance implements ShouldQueue, ShouldBeUnique, ShouldHandleEventsAfterCommit
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    protected $order;
    protected $updateCaptainReportBalance;
    /**
     * Create a new job instance.
     */
    public function __construct(Order $order , UpdateCaptainReportBalanceAction $updateCaptainReportBalance)
    {
        $this->order = $order;
        $this->updateCaptainReportBalance = $updateCaptainReportBalance;
    }

    /**
     * Handle the event.
     *
     * @param  OrderDeliveryFinish  $event
     * @return void
     */
    public function handle(OrderDeliveryFinish $event)
    {
        // find adjacent orders
        $order = Order::with('shop')->find($event->order['id']);
        $order_amount = $order->amount;
        $receivable_amount = 0;
        if ($order->delivery_payment_mode == Order::ORDER_PAYMENT_MODE_PRE_PAID) {
            $order_amount = 0;
        }

        $captain_previous_balance = CaptainOrderPayment::where('captain_id', $order->captain_id)->latest()->first();
        $previous_balance = $captain_previous_balance ? $captain_previous_balance->balance : 0;

        if ($order_amount > 0 && $order->status_id == OrderStatus::DELIVERED) {
            $captain_payed_at_shop = $order->shopPayment;

            $captain_payed_at_store_amount = $captain_payed_at_shop ? $captain_payed_at_shop->amount : 0;

            $order_payment = $order->payment;

            $captain_received_amount = $order_payment ? ($order_payment->payment_mode == OrderPayment::BY_CASH ? $order_payment->given_amount : $order_payment->cash) : 0;

            $receivable_amount = $captain_payed_at_store_amount - $captain_received_amount;
        }

        $balance = $previous_balance - $receivable_amount;

        if ($captainPayment = $order->captainPayment) {
            $captainPayment->captain_id = $order->captain_id;
            $captainPayment->type = 0;
            $captainPayment->transferring_amount = 0;
            $captainPayment->balance = $balance; // need to be calculated
            $captainPayment->accepted_at = null;
            $captainPayment->declined_at = null;
            $captainPayment->updated_by = $event->user ? $event->user->id : 1;

            $captainBalanceData = $captainPayment;
        } else {
            $captainBalanceData = $order->captainPayment()->create([
                'captain_id' => $order->captain_id,
                'type' => 0,
                'transferring_amount' => 0,
                'balance' => $balance,
                'created_by' => $event->user ? $event->user->id : 1,
            ]);
        }
        $this->updateCaptainReportBalance->execute($captainBalanceData);

    }
}
