<?php

namespace App\Listeners;

use App\Actions\CaptainReportCommissionDeletedAction;
use App\CaptainCommission;
use App\Events\OrderReDispatching;
use App\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class ReCalculateCaptainOrderCommission implements ShouldQueue
{

    public function __construct(private CaptainReportCommissionDeletedAction $action)
    {
    }

    /**
     * Handle the event.
     *
     * @param  OrderReDispatching  $event
     * @return void
     */
    public function handle(OrderReDispatching $event)
    {
        // find adjacent orders
        $order = Order::with('captain', 'captainCommission')->find($event->order['id']);
        $captain = $order->captain;
        $captain_commission = $order->captainCommission;

        if(!$captain_commission) {
            return;
        }

        $after_order_commissions = CaptainCommission::where([
            ['captain_id', $captain_commission->captain_id],
            ['id', '>', $captain_commission->id]
        ])->get();

        if($after_order_commissions->count() > 0) {
            $after_order_commissions->each(function($commission) use ($captain_commission) {
                $commission->update([
                    'balance' => $commission->balance - $captain_commission->commission,
                ]);
            });
        }
        $captain_commission->delete();

        $this->action->execute($captain_commission);
    }
}
