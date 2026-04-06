<?php

namespace App\Observers;

use App\ShiftStatus;
use App\Services\StreamlineCacheService;

class ShiftStatusObserver
{
    protected $cacheService;

    public function __construct(StreamlineCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Handle the ShiftStatus "saved" event.
     *
     * @param  \App\ShiftStatus  $shift
     * @return void
     */
    public function saved(ShiftStatus $shift)
    {
        $captain = $shift->captain;
        if (!$captain) {
            return;
        }

        // If shift_end is set, the captain is likely offline
        // If it's a new shift (shift_start is new) or location-based update, update the cache
        if ($shift->wasChanged('shift_end') && $shift->shift_end !== null) {
            $this->cacheService->removeCaptain($captain);
        } else {
            $this->cacheService->updateCaptain($captain);
        }
    }

    /**
     * Handle the ShiftStatus "deleted" event.
     *
     * @param  \App\ShiftStatus  $shift
     * @return void
     */
    public function deleted(ShiftStatus $shift)
    {
        $captain = $shift->captain;
        if ($captain) {
            $this->cacheService->removeCaptain($captain);
        }
    }
}
