<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Events\OrderReDispatching;
use App\Order;
use App\ThirdPartyCommission;

class ReCalculateThirdPartyOrderCommission implements ShouldQueue
{

    /**
     * Handle the event.
     */
    public function handle(OrderReDispatching $event)
    {
        // find 3pl commission
      
        $third_party_commission = ThirdPartyCommission::where('order_id',$event->order['id'])->first();
        if(!$third_party_commission) {
            return;
        }
      
           $third_party_company_id = $third_party_commission->third_party_company_id;

           $after_order_commissions = ThirdPartyCommission::where([
                ['third_party_company_id', $third_party_company_id],
                ['id', '>', $third_party_commission->id]
            ])->get();

        if($after_order_commissions->count() > 0) {
            $after_order_commissions->each(function($commission) use ($third_party_commission) {
                $commission->update([
                    'balance' => $commission->balance - $third_party_commission->total_earned_commission,
                ]);
            });
        }
        $third_party_commission->delete();
    }
}
