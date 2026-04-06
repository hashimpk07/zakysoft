<?php

namespace App\Listeners;

use App\Actions\UpdateCaptainWorkLog;
use App\Events\CaptainShiftClosed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class LogCaptainWorkingTimeListener
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
    public function handle(CaptainShiftClosed $event): void
    {
        (new UpdateCaptainWorkLog())->execute($event->captain);
    }
}
