<?php

namespace App\Jobs;

use App\Client;
use App\ClientShop;
use App\DeliveryChargeRule;
use App\DeliveryChargeRulePrice;
use App\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FindDeliveryCharge implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $order;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $deliveryCharge = $this->findDeliveryCharge();
            if($deliveryCharge) {
                $this->updateDeliveryCharge($deliveryCharge);
            }
        } catch (\Exception $e) {
            Log::error('Delivery Charge Finding error :'. $e->getMessage());
            Log::error('place :'. $e->getFile() . ' line :'. $e->getLine());
        }
    }

    private function  findDeliveryCharge()
    {
        $client = $this->order->client;
       
        // if($client->payment_mode == Client::PAYMENT_MODE_AUTO) {
        //     return false;
        // }
        
        $clientShop = $this->order->shop;
        $fallback_rule = $this->order->client->fallbackRule->delivery_charge_rule_id ?? 0;
      
        if($clientShop->delivery_price_rule_id || $fallback_rule){

           $deliveryChargeRule =  DeliveryChargeRule::find($clientShop->delivery_price_rule_id ?? $fallback_rule);
           $adapter = ClientShop::DELIVER_CHARGERS[$deliveryChargeRule->delivery_charge_based_on];
           $deliveryCharge = (new $adapter)->deliveryCharge($this->order);
           return round($deliveryCharge, 2);

        }
        else
           return false;
        
    }

    private function updateDeliveryCharge($deliveryCharge)
    {
        $this->order->update([
            'delivery_charge' => $deliveryCharge
        ]);
    }
}
