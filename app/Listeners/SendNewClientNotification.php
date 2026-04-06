<?php

namespace App\Listeners;

use App\Events\NewClient;
use App\Mail\ClientRegistrationCompleted;
use App\Mail\ClientRegistrationForClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendNewClientNotification
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
    public function handle(NewClient $event)
    {
        foreach (config('services.client.notification.client_send_emails') as $key => $email) {
            if(filter_var($email, FILTER_VALIDATE_EMAIL)) { 
                Mail::to($email)->send(new ClientRegistrationCompleted($event->client));
            }
        }
        Mail::to($event->client->user->email)->send(new ClientRegistrationForClient($event->client));
    }
}
