<?php

namespace App\Listeners;

use App\Events\CaptainRegistrationRequest;
use App\Mail\NewCaptainRegistrationRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendCaptainRegistrationRequestNotification
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
     * @param  CaptainRegistrationRequest  $event
     * @return void
     */
    public function handle(CaptainRegistrationRequest $event)
    {
        Mail::to('tech@4ulogistic.com')->send(new NewCaptainRegistrationRequest($event->captain));
    }
}
