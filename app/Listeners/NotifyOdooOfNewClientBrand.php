<?php

namespace App\Listeners;

use App\Events\ClientBrandCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\ClientBrand;
use App\Client;

class NotifyOdooOfNewClientBrand
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(ClientBrandCreated $event): void
    {
        Log::info('NotifyOdooOfNewClientBrand ::handle:: -------'.json_encode($event)); 
        try {           
           
            $brand = $event->clientBrand;   
            $client_id = $brand->client_id;
            $code = Client::find($client_id)->code;
            $payload = [
                'leajlak_id'=>$brand->id,
                'name' => $brand->name_en,
                'client_id' => $code,
                // 'cr' =>  'BRD'. str_pad($brand->id, 3, '0', STR_PAD_LEFT),
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
    
            Log::info('NotifyOdooOfNewClientBrand :: Payload to Odoo: ' . json_encode($payload));
    
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . config('odoo.api_key'),
            ])->post(config('odoo.odoo_path') . '/brand/create', $payload);
    
            if ($response->successful()) {
                $responseData = $response->json('result');
                Log::info('NotifyOdooOfNewClientBrand :: Success Response :: ' . json_encode($response->json()));
                
                ClientBrand::where('id', $brand->id)->update(['odoo_brand_id' => $responseData['id']]);

            } else {
                Log::info('NotifyOdooOfNewClientBrand :: Failed :: Status=' . json_encode($response->status()));
                Log::info('NotifyOdooOfNewClientBrand :: Error :: ' . json_encode($response->body()));
            }
        }catch(\Exception $e){
            Log::info('NotifyOdooOfNewClientBrand :: Exception :: ' . json_encode($e->getMessage()));
        }


    }
}
