<?php

namespace App\Jobs;

use App\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\CancellationReasonDeliveryOrderReturnCharge;
use App\DeliveryCancellationCharge;
use App\OrderDeliveryCharge;
use App\OrderLog;
use App\OrderPendingReason;
use App\OrderStatus;
use Exception;
use Log;

class UpdateCancelReturnDeliveryCharge implements ShouldQueue
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
       try {
        if($this->order->status_id == OrderStatus::CANCEL || $this->order->status_id == OrderStatus::CANCEL_REQUEST_ACCEPTED){
              $lastOrderStatus =  OrderLog::where('order_id', $this->order->id)->orderBy('id', 'desc')->skip(1)->take(1)->first();
              $fallback_rule = $this->order->client->fallbackRule ? $this->order->client->fallbackRule->delivery_cancellation_charge_rule_id : 0;
                if($lastOrderStatus){
                        $status_id = $lastOrderStatus->status_id;
                        $clientShop = $this->order->shop;
                        $deliveryCancelationCharge =  DeliveryCancellationCharge::join('cancellation_charge_applicable_statuses','cancellation_charge_applicable_statuses.delivery_cancellation_charge_id','delivery_cancellation_charges.id')
                                                    ->where('applicable_when_status_id', $status_id )
                                                    ->where('delivery_cancellation_charges.id','=',$clientShop->cancellation_rule_id ?? $fallback_rule)
                                                    ->where('delivery_cancellation_charges.status',1)
                                                    ->first();
                  
                        if($deliveryCancelationCharge ){
                            
                            if( $deliveryCancelationCharge->based_on == 'base_delivery_price' ){
                                $this->order->delivery_charge =  $this->order->orderDeliveryCharge->basic_delivery_charge;
                            }
                            elseif( $deliveryCancelationCharge->based_on == 'fixed_amount' ){
                                $this->order->delivery_charge = $deliveryCancelationCharge->fixed_amount;
                            }
                            else{
                                $percentage_of_base_delivery_charge = round(($this->order->orderDeliveryCharge->basic_delivery_charge * $deliveryCancelationCharge->percentage_of_base_delivery_charge ) /100, 2);
                                $this->order->delivery_charge = $percentage_of_base_delivery_charge;
                            }
                            $this->order->save();
                        }else{
                            $this->order->delivery_charge = 0;
                            $this->order->save();
                        }
                } else{
                    $this->order->delivery_charge = 0;
                    $this->order->save();
                }

                $delivery = $this->updateDeliveryCharge($clientShop->cancellation_rule_id, 0, 0, $this->order->delivery_charge);
            }
            if($this->order->status_id == OrderStatus::CLIENT_RETURN_ACCEPTED ){
              $fallback_rule = $this->order->client->fallbackRule ? $this->order->client->fallbackRule->delivery_return_rule_id : 0;
                $reason = null;
                $cancelationReason =  OrderLog::where('order_id', $this->order->id)->where('status_id', OrderStatus::PENDING)->latest()->first();

                if($cancelationReason)
                $reason = OrderPendingReason::select('order_pending_reasons.*')->leftjoin('cancellation_reasons','cancellation_reasons.pending_reason_id','order_pending_reasons.id')
                        ->orwhere('order_pending_reasons.id',$cancelationReason->reason_id)
                        ->first();

                if(!$reason ){
                    $cancelationReasonNote =  OrderLog::where('order_id', $this->order->id)->latest()->first()->note;
                    $reason = OrderPendingReason::select('order_pending_reasons.*')->leftjoin('cancellation_reasons','cancellation_reasons.pending_reason_id','order_pending_reasons.id')
                    ->where('order_pending_reasons.reason',$cancelationReasonNote)->orWhere('order_pending_reasons.reason_ar',$cancelationReasonNote)
                    ->orwhere('cancellation_reasons.reason',$cancelationReasonNote)->orWhere('cancellation_reasons.reason_ar',$cancelationReasonNote)
                    ->first();
                }
            
                if ($reason || ($cancelationReason && $cancelationReason->reason_id == 0)) {
                    $clientShop = $this->order->shop;

                    if($cancelationReason->reason_id == 0){
                        $deliveryReturnCharge = CancellationReasonDeliveryOrderReturnCharge::select('cancellation_reason_id','delivery_order_return_charges.*')
                        ->leftjoin('delivery_order_return_charges','cancellation_reason_delivery_order_return_charge.delivery_order_return_charge_id','delivery_order_return_charges.id')
                        ->where('cancellation_reason_id',0)
                        ->where('status',1)
                        ->where('delivery_order_return_charges.id','=',$clientShop->delivery_order_return_charge_id ?? $fallback_rule)
                        ->first();
                    }else{
                        $deliveryReturnCharge = CancellationReasonDeliveryOrderReturnCharge::select('cancellation_reason_id','delivery_order_return_charges.*')
                        ->leftjoin('delivery_order_return_charges','cancellation_reason_delivery_order_return_charge.delivery_order_return_charge_id','delivery_order_return_charges.id')
                        ->where('cancellation_reason_id',$reason->id)
                        ->where('status',1)
                        ->where('delivery_order_return_charges.id','=',$clientShop->delivery_order_return_charge_id ?? $fallback_rule)
                        ->first();
                    }
                   
                    if($deliveryReturnCharge){
                        if( $deliveryReturnCharge->based_on == 'base_delivery_price' ){
                            $this->order->delivery_charge = $this->order->orderDeliveryCharge->basic_delivery_charge;
                        }
                        elseif( $deliveryReturnCharge->based_on == 'fixed_amount' ){
                            $this->order->delivery_charge = $deliveryReturnCharge->fixed_amount;
                        }
                        elseif( $deliveryReturnCharge->based_on == 'percentage_of_base_delivery_charge' ){
                            $percentage_of_base_delivery_charge = round(($this->order->orderDeliveryCharge->basic_delivery_charge * $deliveryReturnCharge->percentage_of_base_delivery_charge ) /100, 2);
                            $this->order->delivery_charge = $percentage_of_base_delivery_charge;
                        }
                        else{
                            $percentage_of_total_delivery_charge = round(($this->order->orderDeliveryCharge->total_earnings * $deliveryReturnCharge->percentage_of_total_delivery_charge ) /100, 2);
                            $this->order->delivery_charge = $percentage_of_total_delivery_charge;
                        }
                        $this->order->save();
        
                    }else{
                        $this->order->delivery_charge = 0;
                        $this->order->save();
                    }
        
                }
                else{
                    $this->order->delivery_charge = 0;
                    $this->order->save();
                }
                
                $delivery = $this->updateDeliveryCharge($clientShop->delivery_order_return_charge_id, 0, 0, $this->order->delivery_charge);
            }
        } catch (\Exception $e) {
            Log::error('Delivery Charge update error :'. $e->getMessage());
            Log::error('place :'. $e->getFile() . ' line :'. $e->getLine());
        }

    }

    public function updateDeliveryCharge($rule_id,$additional_coast,$add_distance,$deliveryCharge) {
      
         $val = OrderDeliveryCharge::updateOrCreate([
                'order_id' => $this->order->id
             ], [
            'commission_rule_id' => $rule_id,
            'additional_km_earning' => $additional_coast,
            'additional_km' => $add_distance,
            'basic_delivery_charge' =>  $deliveryCharge,
            'total_earnings' => $deliveryCharge,
            'vat' => $deliveryCharge * 0.05,
        ]);
      
    }
}
