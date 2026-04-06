<?php
namespace App\Adapters;

use App\DeliveryChargeRule;
use App\DeliveryChargeRulePriceZone;
use App\Order;
use App\OrderDeliveryCharge;

class DeliveryChargeBasedOnZone implements DeliveryCharge {
  
    public function deliveryCharge(Order $order)
    {
        $fallback_rule = $this->order->client->fallbackRule->delivery_charge_rule_id ?? 0;
        $clientShop = $order->shop;
        $zone = $clientShop->zone_id;

        $rule_id = $clientShop->delivery_price_rule_id ?? $fallback_rule;
      
        if(!$rule_id){
            return 0;
        }
        $deliveryChargeRule =  DeliveryChargeRule::find($rule_id);
        if(!$deliveryChargeRule){
            return 0;
        }

        $charge = $this->charge($deliveryChargeRule, $zone);
        
        
        OrderDeliveryCharge::updateOrCreate([
                'order_id' => $order->id,
                'commission_rule_id' => $deliveryChargeRule->id,
            ],[
            'additional_km_earning' => $charge['additional_coast'],
            'additional_km' => $charge['add_distance'],
            'basic_delivery_charge' =>  $charge['delivery_charge'],
            'total_earnings' =>  $charge['delivery_charge'],
            'vat' => $charge['delivery_charge'] * 0.05,
        ]);

        return $charge['delivery_charge'];
    }
    
    public function charge($rule, $zone) {
        $charge = DeliveryChargeRulePriceZone::select('base_delivery_charge')
            ->join('delivery_charge_rule_prices','delivery_charge_rule_price_zone.delivery_charge_rule_price_id','delivery_charge_rule_prices.id')
            ->join('delivery_charge_rules','delivery_charge_rule_prices.delivery_charge_rule_id','delivery_charge_rules.id')
            ->where('delivery_charge_rule_price_zone.zone_id', $zone)
            ->where('delivery_charge_rules.id', $rule->id)
            ->where('delivery_charge_rules.status', 1)
            ->first();

        return [
            'delivery_charge' => $charge->base_delivery_charge ?? 0,
            'additional_coast' => 0,
            'add_distance' => 0
        ];
    }
}