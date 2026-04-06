<?php

namespace App\Listeners;

use App\Events\UpdateClientShop;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\ClientShop;
use App\ClientBrand;
use App\Client;

class NotifyOdooOfUpdateClientShop
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
    public function handle(UpdateClientShop $event): void
    {
        Log::info('NotifyOdooOfUpdateClientShop ::handle:: -------'.json_encode($event)); 
        try {    
            $client_shop    = $event->client_shop;
            $clientshop     = ClientShop::find($client_shop->id);
            Log::info('NotifyOdooOfNewBranch ::clientshop:: -------'.json_encode($clientshop)); 
            $brand          = ClientBrand::where("client_id", $clientshop->client_id)->first();
            Log::info('NotifyOdooOfNewBranch ::brand:: -------'.json_encode($brand)); 
            $code = Client::find($clientshop->client_id)->code;

            $payload = [
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
                // 'cr' =>  $clientshop->getBranchCR(),                
                // 'brand' =>$brand->getBrandCR(),              
            ];
    
            Log::info('NotifyOdooOfUpdateClientShop :: Payload to Odoo: ' . json_encode($payload));
    
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . config('odoo.api_key'),
            ])->put(config('odoo.odoo_path') . '/branch/update', $payload);
    
            if ($response->successful()) {
                $responseData = $response->json('result');
                Log::info('NotifyOdooOfUpdateClientShop :: Success Response :: ' . json_encode($response->json()));
                
                // Client::where('id', $client->id)->update(['odoo_client_id' => $responseData['id']]);

            } else {
                Log::info('NotifyOdooOfUpdateClientShop :: Failed :: Status=' . json_encode($response->status()));
                Log::info('NotifyOdooOfUpdateClientShop :: Error :: ' . json_encode($response->body()));
            }
        }catch(\Exception $e){
            Log::info('NotifyOdooOfUpdateClientShop :: Exception :: ' . json_encode($e->getMessage()));
        }
    }
}
