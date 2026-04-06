<?php

namespace App\Jobs;

use App\Captain;
use App\CaptainThirdPartyLogistic;
use App\Http\Controllers\ThirdPartyCommissionReportController;
use Illuminate\Support\Facades\Log;
use App\Order;
use App\OrderLog;
use App\OrderStatus;
use App\ThirdPartyCommission;
use App\ThirdPartyLogisticCompanyDeliveryPriceSetting;
use App\ThirdPartyLogisticCompanyDeliveryPriceSettingRule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateThirdPartyOrderBalance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $order;
    /**
     * Create a new job instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
            $type =  $this->order->captain->vehicle->type;
  
            $third_party_company_id = CaptainThirdPartyLogistic::where('captain_id',$this->order->captain_id)->first()->third_party_logistic_company_id;

            $priceRule =   ThirdPartyLogisticCompanyDeliveryPriceSetting::select('third_party_logistic_company_delivery_price_settings.*','tlsr.vehicle_type_id','tlsr.base_delivery_charge','tlsr.base_delivery_km','tlser.km_from','tlser.km_to','tlser.price_per_km')
                        ->leftJoin('third_party_logistic_company_delivery_price_setting_rules as tlsr','third_party_logistic_company_delivery_price_settings.id','tlsr.third_party_logistic_company_delivery_price_settings_id')
                        ->leftJoin('third_party_logistic_company_delivery_price_setting_extra_rules as tlser', 'tlsr.id','tlser.third_party_logistic_company_delivery_price_rule_id')
                        ->where('tlsr.vehicle_type_id','=',$type) 
                        ->where('third_party_logistic_company_delivery_price_settings.third_party_logistic_company_id',$third_party_company_id)
                        ->first();
          
            
            if( $priceRule){
           

               $commission =  $this->findCommission($priceRule, $this->order);

               $latestCommission = ThirdPartyCommission::where('third_party_company_id',$third_party_company_id)->latest('id')->first();
               $currentBalance = isset( $latestCommission)? $latestCommission->balance : 0;

               $third_party_commission = ThirdPartyCommission::updateOrCreate(
                ['order_id' => $this->order->id],
                [
                    'third_party_company_id' => $third_party_company_id,    
                    'commission_rule_id'=>$priceRule->id,
                    'basic_delivery_earnings' => round($commission[0], 2),
                    'additional_km' => round($commission[1], 2),
                    'additional_km_earning'   => round($commission[2], 2),
                    'total_earned_commission' => round($commission[0] + $commission[2], 2),
                    'balance' => round(($currentBalance +  $commission[0] + $commission[2]), 2),
                    'created_at' => now(),
                    'updated_at' => now()
                   ]
                );
      
            }
    }

    public function findCommission($priceRule, $order) {
      
        $travel_distance = abs($order->shop_to_delivery_km); 
        $base_commission = 0;
        $additional_km = 0;
        $additional_km_commission = 0;

        
        if($travel_distance - $priceRule->base_delivery_km > 0){
          
            $additional_km = $travel_distance - $priceRule->base_delivery_km;
            $base_commission  = $priceRule->base_delivery_charge;
            if($additional_km < $priceRule->km_to){
                $additional_km_commission = abs($additional_km) * $priceRule->price_per_km;
           }
         
        }else{
            $base_commission  = $priceRule->base_delivery_charge;
        }

        if($this->order->status_id == OrderStatus::DELIVERED ){
           return [$base_commission, $additional_km, $additional_km_commission];    
        }
        elseif($this->order->status_id == OrderStatus::CANCEL_REQUEST_ACCEPTED ){
         
                $lastOrderStatus =  OrderLog::where('order_id', $this->order->id)->orderBy('id', 'desc')->skip(1)->take(1)->first();
                
                if($lastOrderStatus && $priceRule->cancellation_charge_applicable == 1){
                        $status_id = $lastOrderStatus->status_id;
                       
                        if(in_array($status_id, json_decode($priceRule->cancellation_if_order_status_reached_status_id))){
                     
                            if($priceRule->cancellation_charge_strategy == ThirdPartyLogisticCompanyDeliveryPriceSettingRule::FIXED_AMOUNT ){
                                $base_commission = $priceRule->cancellation_fixed_amount;
                                
                            }elseif($priceRule->cancellation_charge_strategy == ThirdPartyLogisticCompanyDeliveryPriceSettingRule::PERCENTAGE_OF_BASE_PRICE ){
                                $base_commission = round(( $base_commission * $priceRule->cancellation_percentage_of_base_delivery_price ) /100, 2);
                            }else{
                                $total_commission = $base_commission + $additional_km_commission;
                                $base_commission = round(( $total_commission * $priceRule->cancellation_percentage_of_final_delivery_price ) /100, 2);
                            }
                            return [$base_commission, $additional_km, 0];    
                        }else{
                            return [0, 0, 0]; 
                        }
                         
                }else{
                    return [0, 0, 0];   
                }

        }
        elseif($this->order->status_id == OrderStatus::CLIENT_RETURN_ACCEPTED ){
            $lastOrderStatus =  OrderLog::where('order_id', $this->order->id)->orderBy('id', 'desc')->first();
            if($lastOrderStatus && $priceRule->return_order_charge_applicable == 1){
                    $status_id = $lastOrderStatus->status_id;
              
                    if(in_array($status_id, json_decode($priceRule->return_order_if_order_status_reached_status_id))){
                    
                        if($priceRule->return_order_charge_strategy == ThirdPartyLogisticCompanyDeliveryPriceSettingRule::FIXED_AMOUNT ){
                            $base_commission = $priceRule->return_order_fixed_amount;
                            
                        }elseif($priceRule->return_order_charge_strategy == ThirdPartyLogisticCompanyDeliveryPriceSettingRule::PERCENTAGE_OF_BASE_PRICE ){
                            $base_commission = round(( $base_commission * $priceRule->return_order_percentage_of_base_delivery_price ) /100, 2);
                        }else{
                            $total_commission = $base_commission + $additional_km_commission;
                            $base_commission = round((  $total_commission * $priceRule->return_order_percentage_of_final_delivery_price ) /100, 2);
                        }
                        return [$base_commission, $additional_km, 0];  
                    }else{
                        return [0, 0, 0];
                    }
                       
            }else{
                return [0, 0, 0];   
            }

        }
    
    }

}
