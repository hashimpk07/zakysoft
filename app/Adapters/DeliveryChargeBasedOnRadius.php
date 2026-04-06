<?php
namespace App\Adapters;

use App\ClientShopDeliveryChargeBasedOnRadius;
use App\DeliveryChargeRulePrice;
use App\DeliveryChargeRulePriceExtraRule;
use App\Order;
use App\OrderDeliveryCharge;
use App\OrderStore;
use Illuminate\Support\Facades\Log;

class DeliveryChargeBasedOnRadius implements DeliveryCharge {
    public function deliveryCharge(Order $order)
    {
        $shop = $order->shop;
        $fallback_rule = $order->client->fallbackRule->delivery_charge_rule_id ?? 0;

        $rule_id = $shop->delivery_price_rule_id ?? $fallback_rule;
  
        if(!$rule_id) {
            return 0;
        }

        $deliveryChargeRule =  $shop->deliveryChargeRule ?? $order->client->fallbackRule->deliveryPice;
        
        if(!$deliveryChargeRule ) { 
            return 0;
        }

        $distance = $this->distance($order);
        
        $charges = $this->charge($deliveryChargeRule, $distance);

        if(!$charges) {
            return 0;
        }

        $this->updateDeliveryCharge($deliveryChargeRule, $order, $charges['additional_coast'], $charges['add_distance'], $charges['delivery_charge']);

        return $charges['delivery_charge'];
    }

    public function updateDeliveryCharge($rules, $order,$additional_coast,$add_distance,$deliveryCharge) {

        OrderDeliveryCharge::updateOrCreate([
                'order_id' => $order->id,
                'commission_rule_id' => $rules->id,
            ], [
            'additional_km_earning' => $additional_coast,
            'additional_km' => $add_distance,
            'basic_delivery_charge' =>  $deliveryCharge - $additional_coast,
            'total_earnings' =>  $deliveryCharge,
            'vat' => $deliveryCharge * 0.05,
        ]);
        
    }

    public function distance($order)
    {
        return  $order->shop_to_delivery_km ?? 0;
    }

    public function charge($deliveryChargeRule, $distance)
    {
        $base_price =  DeliveryChargeRulePrice::query()
            ->where('delivery_charge_rule_id', '=', $deliveryChargeRule->id)
            ->first();
        
        if(!$base_price) {
            return;
        }

        $extraRule = DeliveryChargeRulePriceExtraRule::query()
            ->where([
                ['from_kilometer','<',$distance],
                ['to_kilometer','>=',$distance],
                ['delivery_charge_rule_price_id',$base_price->id]
            ])
            ->orderBy('to_kilometer','desc')
            ->first();

        if(!$extraRule){
            $extraRule = DeliveryChargeRulePriceExtraRule::query()
                ->where([
                    ['from_kilometer','<',$distance],
                    ['to_kilometer','=',''],
                    ['delivery_charge_rule_price_id',$base_price->id]
                ])
                ->orderBy('to_kilometer','desc')
                ->first();
        }

        if($extraRule){
            $strategy = $extraRule->delivery_charge_radius_scheme;
            return $this->{$strategy.'Calculate'}($base_price, $extraRule, $distance);
        }

        return [
            'delivery_charge' => $base_price->base_delivery_charge,
            'additional_coast' => 0,
            'add_distance' => 0,
            'total_distance' => $distance
        ];
    }

    public function fixedCalculate($base_price, $rules, $distance)
    {
        $deliveryCharge = 0;
        
        $deliveryCharge = $base_price->base_delivery_charge;
        $additional_coast = $add_distance = 0;

        if($distance - $base_price->base_delivery_radius_kilometer > 0) {
            $add_distance = $distance - $base_price->base_delivery_radius_kilometer;  
            if($rules){
                $additional_coast = $rules->charge;
                $deliveryCharge += $additional_coast;
            }
        }

        return [
            'delivery_charge' => $deliveryCharge,
            'additional_coast' => $additional_coast,
            'add_distance' => $add_distance,
            'total_distance' => $distance
        ];
    }

    public function flexibleCalculate($base_price, $rules, $distance)
    {
        $deliveryCharge = 0;
        $additional_coast = $add_distance = 0;
    

        $deliveryCharge = $base_price->base_delivery_charge;

        if($distance - $base_price->base_delivery_radius_kilometer > 0) {
            $add_distance = $distance - $base_price->base_delivery_radius_kilometer;  
            if($rules){
                $additional_coast = $add_distance * $rules->charge;
                $deliveryCharge += $additional_coast;
            }
        }

        return [
            'delivery_charge' => $deliveryCharge,
            'additional_coast' => $additional_coast,
            'add_distance' => $add_distance,
            'total_distance' => $distance
        ];
    }
}