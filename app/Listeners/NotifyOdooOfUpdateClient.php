<?php

namespace App\Listeners;

use App\Events\UpdateClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Client;

class NotifyOdooOfUpdateClient
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
    public function handle(UpdateClient $event): void
    {
        Log::info('NotifyOdooOfUpdateClient ::handle:: -------'.json_encode($event)); 
     
        try {    
            $client = $event->client;
            $user = $client->user;
              $payload = [
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
            
            Log::info('NotifyOdooOfUpdateClient :: Payload to Odoo: ' . json_encode($payload));
    
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . config('odoo.api_key'),
            ])->put(config('odoo.odoo_path') . '/client/update', $payload);
    
            if ($response->successful()) {
                $responseData = $response->json('result');
                Log::info('NotifyOdooOfUpdateClient :: Success Response :: ' . json_encode($response->json()));
                
                // Client::where('id', $client->id)->update(['odoo_client_id' => $responseData['id']]);

            } else {
                Log::info('NotifyOdooOfUpdateClient :: Failed :: Status=' . json_encode($response->status()));
                Log::info('NotifyOdooOfUpdateClient :: Error :: ' . json_encode($response->body()));
            }
        }catch(\Exception $e){
            Log::info('NotifyOdooOfUpdateClient :: Exception :: ' . json_encode($e->getMessage()));
        }
    }
}
