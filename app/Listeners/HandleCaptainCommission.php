<?php

namespace App\Listeners;

use App\CaptainReport;
use App\Events\ClientDeclinedReturn;
use App\Order;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleCaptainCommission implements ShouldQueue, ShouldBeUnique, ShouldHandleEventsAfterCommit
{

    /**
     * Create the event listener.
     */
    public function __construct()
    {

    }

    /**
     * Handle the event.
     */
    public function handle(ClientDeclinedReturn $event)
    {
        //* Find the order and captain associated with this event
        $order = Order::with('orderStatus', 'payment', 'shopPayment', 'captain.orderPayableBalance', 'captainCommission')->find($event->order['id']);

        $captain = $order->captain;
        $captain_commission = $order->captainCommission;

        //* Get the latest CaptainReport for the captain, or null if it doesn't exist
        $captain_reports = CaptainReport::where('captain_id', $captain->id)->latest()->first();

        $previous_total_commission = $captain_reports->total_commission ?? 0;
        $previous_balance_commission = $captain_reports->balance_commission ?? 0;

        $total_commission = ($captain_commission->commission ?? 0) + $previous_total_commission;
        $balance_commission = ($captain_commission->balance ?? 0) + $previous_balance_commission;

        //* Update or create the CaptainReport with the new values
        CaptainReport::updateOrCreate(
            ['captain_id' => $captain->id],
            [
                'total_commission' => $total_commission,
                'balance_commission' => $balance_commission,
            ]
        );
    }
}
