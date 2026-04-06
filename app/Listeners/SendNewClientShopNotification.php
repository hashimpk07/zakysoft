<?php

namespace App\Listeners;

use App\Events\NewClient;
use App\Events\NewClientShop;
use App\Files_and_remainders;
use App\Mail\ClientShopRegistrationCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendNewClientShopNotification
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  NewClient  $event
     * @return void
     */
    public function handle(NewClientShop $event)
    {
        $client_shop = $event->client_shop;

        $data['name'] = $client_shop->name;
        $data['date'] = $client_shop->created_at;
        $data['type'] = "Client Shop";
        $data['detail'] = 'New client shop created under client: ' . $client_shop->client->user->name;
        $data['reference_path'] = route('clients.show', $client_shop->client->id).'#' .$client_shop->id;
        $data['reference_id'] = $client_shop->id;

        Files_and_remainders::create($data);
        foreach (config('services.client.notification.client_shop_send_emails') as $key => $email) {
            if(filter_var($email, FILTER_VALIDATE_EMAIL)) { 
                Mail::to($email)->send(new ClientShopRegistrationCompleted($client_shop));
            }
        }

        foreach (config('services.client.notification.client_specific_shop_send_emails.' . $client_shop->client->id, []) as $key => $email) {
            if(filter_var($email, FILTER_VALIDATE_EMAIL)) { 
                Mail::to($email)->send(new ClientShopRegistrationCompleted($client_shop));
            }
        }
    }
}
