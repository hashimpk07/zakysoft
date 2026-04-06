<?php

namespace App\Listeners;

use App\Events\ClientBrandUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Client;


class NotifyOdooOfUpdateClientBrand
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
    public function handle(ClientBrandUpdated $event): void
    {
        Log::info('NotifyOdooOfUpdateClientBrand ::handle:: -------'.json_encode($event)); 
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
    
            Log::info('NotifyOdooOfUpdateClientBrand :: Payload to Odoo: ' . json_encode($payload));
    
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . config('odoo.api_key'),
            ])->put(config('odoo.odoo_path') . '/brand/update', $payload);
    
            if ($response->successful()) {
                $responseData = $response->json('result');
                Log::info('NotifyOdooOfUpdateClientBrand :: Success Response :: ' . json_encode($response->json()));
                
                // Client::where('id', $client->id)->update(['odoo_client_id' => $responseData['id']]);

            } else {
                Log::info('NotifyOdooOfUpdateClientBrand :: Failed :: Status=' . json_encode($response->status()));
                Log::info('NotifyOdooOfUpdateClientBrand :: Error :: ' . json_encode($response->body()));
            }
        }catch(\Exception $e){
            Log::info('NotifyOdooOfUpdateClientBrand :: Exception :: ' . json_encode($e->getMessage()));
        }
    }
}
