<?php

namespace App\Listeners;

use App\ClientBrand;
use App\Events\NewClientShop;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\ClientShop;
use App\Client;

class NotifyOdooOfNewBranch
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
    public function handle(NewClientShop $event): void
    {
        Log::info('NotifyOdooOfNewBranch ::handle:: -------'.json_encode($event)); 
        try {           
           
            $client_shop    = $event->client_shop;
            $clientshop     = ClientShop::find($client_shop->id);
            Log::info('NotifyOdooOfNewBranch ::clientshop:: -------'.json_encode($clientshop)); 
            $brand          = ClientBrand::where("client_id", $clientshop->client_id)->first();
            Log::info('NotifyOdooOfNewBranch ::brand:: -------'.json_encode($brand)); 
            $code = Client::find($clientshop->client_id)->code;

          /*   {
            "leajlak_id": "12",
            "name": "Branch",
            "brand_id": "11",
            "client_id": "10",
            "cr": "233234",
            "street": "street1",
            "street2": "stree2",
            "city": "kochi",
            "state": "Kerala",
            "country": "India",
            "zip": "682505",
            "phone": "234234",
            "email": "xy@gmail.com",
            "vat": "3242"
            } */
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
    
            Log::info('NotifyOdooOfNewBranch :: Payload to Odoo: ' . json_encode($payload));
    
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . config('odoo.api_key'),
            ])->post(config('odoo.odoo_path') . '/branch/create', $payload);
    
            if ($response->successful()) {
                $responseData = $response->json('result');
                Log::info('NotifyOdooOfNewBranch :: Success Response :: ' . json_encode($response->json()));
                
                ClientShop::where('id', $client_shop->id)->update(['odoo_branch_id' => $responseData['id']]);

            } else {
                Log::info('NotifyOdooOfNewBranch :: Failed :: Status=' . json_encode($response->status()));
                Log::info('NotifyOdooOfNewBranch :: Error :: ' . json_encode($response->body()));
            }
        }catch(\Exception $e){
            Log::info('NotifyOdooOfNewBranch :: Exception :: ' . json_encode($e->getMessage()));
        }

    }
}
