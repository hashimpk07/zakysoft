<?php

namespace App\Observers;

use App\CaptainLocationLog;
use App\Services\StreamlineCacheService;

class CaptainLocationLogObserver
{
    protected $cacheService;

    public function __construct(StreamlineCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Handle the CaptainLocationLog "created" event.
     *
     * @param  \App\CaptainLocationLog  $location
     * @return void
     */
    public function created(CaptainLocationLog $location)
    {
        $captain = $location->captain;
        if ($captain) {
            $this->cacheService->updateCaptain($captain);
        }
    }
}
