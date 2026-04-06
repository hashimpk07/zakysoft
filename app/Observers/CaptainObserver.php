<?php

namespace App\Observers;

use App\Captain;
use App\Services\StreamlineCacheService;

class CaptainObserver
{
    protected $cacheService;

    public function __construct(StreamlineCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Handle the Captain "saved" event.
     *
     * @param  \App\Captain  $captain
     * @return void
     */
    public function saved(Captain $captain)
    {
        $this->cacheService->updateCaptain($captain);
    }

    /**
     * Handle the Captain "deleted" event.
     *
     * @param  \App\Captain  $captain
     * @return void
     */
    public function deleted(Captain $captain)
    {
        $this->cacheService->removeCaptain($captain);
    }
}
