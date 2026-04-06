<?php

namespace App\Services\General\DTO;

use Carbon\Carbon;

final class OperationalDateDTO
{
    public readonly Carbon $businessDayStart;
    public readonly Carbon $businessDayEnd;
    public readonly string $startOfMonth;
    public readonly string $startOfYear;
    public readonly string $date;
    public readonly int    $dayOfMonth;
    public readonly int    $dayOfYear;

    public function __construct(?string $requestedDate = null)
    {
        $currentHour = now()->hour;

        $this->date = $requestedDate
            ?? ($currentHour < 6 ? now()->subDay()->format('Y-m-d') : now()->subDay()->format('Y-m-d'));

        $this->businessDayStart = Carbon::parse($this->date)->setTime(6, 0, 0);
        $this->businessDayEnd   = Carbon::parse($this->date)->addDay()->setTime(5, 59, 59);
        $this->startOfMonth     = Carbon::parse($this->date)->startOfMonth()->format('Y-m-d');
        $this->startOfYear      = Carbon::parse($this->date)->startOfYear()->format('Y-m-d');
        $this->dayOfMonth       = Carbon::parse($this->date)->day;
        $this->dayOfYear        = Carbon::parse($this->date)->dayOfYear;
    }
}