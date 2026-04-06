<?php

namespace App\Jobs;

use App\Order;
use App\OrderReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Client;
use App\OrderStatus;
use App\ClientBrand;
use App\ClientShop;

class NotifyOdooOfNewCreateOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $event;
    /**
     * Create a new job instance.
     */
    public function __construct($event)
    {
        $this->event = $event;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
         Log::info('NotifyOdooOfNewCreateOrderJob ::handle:: -------'.json_encode($this->event)); 
   
         try {              
            $event = json_decode(json_encode($this->event));   
            $order_id =  $event->order->id;
            log::info('NotifyOdooOfNewCreateOrderJob :: Order ID: ' . $order_id);
            $order_details = Order::with('orderStatus','captain','orderDeliveryCharge')->where('id', $order_id)->first();
            log::info('NotifyOdooOfNewCreateOrderJob :: order_details: ' . json_encode($order_details));
            $orderreport  = OrderReport::with('shop.brand')->where('order_id', $order_id)->first();            
            // $order_status = $order_details->orderStatus->name;
            $order_status = $order_details->status_id;
            $order_date_time = Carbon::parse($order_details->created_at)->format('Y-m-d H:i:s') ?? null;
            $order_ref = $order_details->client_order_id;
            $client_id = $order_details->client_id;
            // $code = Client::find($client_id)->code;
            $brand_id   = $orderreport->shop->brand->id ?? null;
            $shop_id = $order_details->shopname ?? null;
            $captain_name = $order_details->captain->firstname ?? null;
            $delivery_date_time = $order_details->delivery_date ?? null;
            $delivery_date_time_formated = Carbon::parse($delivery_date_time)->format('Y-m-d H:i:s') ?? null;
            $distance = $order_details->shop_to_delivery_km ?? null;

            $client = Client::find($client_id);
            $user = $client->user;
  
            $client_details = [
                'leajlak_id' => $client->code,
                'name' => $user->name,
                'cr' => $client->cr_registration_number ?? null,
                'street' => $client->address ?? null,
                'street2' => null,
                'city' => $client->city ?? null,
                'state' => $client->state ?? null,
                'country' => $client->country ?? null,
                'zip' => $client->zip ?? null,
                'phone' => $client->mobile_number ?? null,
                'email' => $user->email ?? null,
                'vat' => $client->vat_registration_number ?? null                
            ];
    
            $brand = ClientBrand::find($brand_id);   
            $client_id = $brand->client_id;
            $code = Client::find($client_id)->code;
            $brand_details = [
                'leajlak_id'=>$brand->id,
                'name' => $brand->name_en,
                'client_id' => $code,
                'cr' => null,
                'street' => null,
                'street2' => null,
                'city' => null,
                'state' => null,
                'country' => null,
                'zip' => null,
                'phone' => null,
                'email' => null,
                'vat' => null                
            ];


            $client_shop     = ClientShop::find($shop_id);
            $code            = Client::find($client_shop->client_id)->code;

            $shop_details = [
                'leajlak_id'=> $client_shop->id,
                'name' => $client_shop->name,
                'brand_id' => $client_shop->client_brand_id,
                'client_id' => $code,
                'cr' =>  null,
                'street' => $client_shop->address ?? null,
                'street2' => null,
                'city' =>  null,
                'state' => null,
                'country' =>  null,
                'zip' => null,
                'phone' => $client_shop->shop_admin_mobile ?? null,
                'email' => $client_shop->shop_email_id ?? null,
                'vat'=>  null,           
            ];

            $payload = [
                'status'=> $order_status,
                'order_id' => $order_id,
                'order_date_time' => $order_date_time,
                'order_ref' =>  $order_ref,
                'client' => $client_details,
                'brand' => $brand_details,
                'shop_id' => $shop_details,
                'captain_name' => $captain_name,
                'delivery_date_time' => $delivery_date_time_formated,
                'order_lines' => [
                    [   // 'basic_delivery_charge'
                        'product_ref' => config('statusids.basic_delivery_charge'),
                        'price' => ($order_details->status_id == OrderStatus::CANCEL) ? 0 : ($order_details->orderDeliveryCharge->basic_delivery_charge ?? 0 )
                    ],
                    [
                        // additional_km_earning
                        'product_ref' => config('statusids.additional_km_earning'),
                        'price' => $order_details->orderDeliveryCharge->additional_km_earning ?? 0
                    ],
                    [
                        // total_earnings
                        'product_ref' => config('statusids.total_earnings'),
                        // 'price' => $order_details->orderDeliveryCharge->total_earnings ?? 0
                        'price' => 0
                    ],
                    [
                        // vat
                        'product_ref' => config('statusids.vat'),
                        'price' => $order_details->orderDeliveryCharge->vat ?? 0
                    ],
                    [
                        // canceled
                        'product_ref' => config('statusids.canceled'),
                        'price' => ($order_details->status_id == OrderStatus::CANCEL) ? $order_details->delivery_charge : 0
                    ],
                    [
                        // expenses
                        'product_ref' => config('statusids.expenses'),
                        'price' => 0
                    ],
                    
                ],
                'distance' => $distance,
                'extra_km' => $order_details->orderDeliveryCharge->additional_km ?? 0,           
            ];
            Log::info('NotifyOdooOfNewCreateOrderJob ::  payload:'. json_encode($payload));
          
    
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . config('odoo.api_key'),
            ])->post(config('odoo.odoo_path') . '/sale_order_book/create', $payload);
    
            if ($response->successful()) {
                $responseData = $response->json('result');
                Log::info('NotifyOdooOfNewCreateOrderJob :: Success Response :: ' . json_encode($response->json()));
                Order::where('id', $order_id)->update(['odoo_order_id' => $responseData['id']]);
            } else {
                Log::error('NotifyOdooOfNewCreateOrderJob :: Error Response :: ' . json_encode($response->json()));
            }
        } catch (\Exception $e) {
            Log::error('NotifyOdooOfNewCreateOrderJob :: Exception: '.$e->getMessage().' Line No: '.$e->getLine());
        }
    }
}
