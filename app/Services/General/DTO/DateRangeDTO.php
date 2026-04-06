<?php

namespace App\Services\General\DTO;
use Carbon\Carbon;

final class DateRangeDTO
{
    public readonly string $startDate;
    public readonly string $endDate;
    public readonly string $fromDateTime;
    public readonly string $toDateTime;

    public function __construct(string $fromDate, string $toDate)
    {
        $from = Carbon::parse($fromDate)->setTime(6, 0, 0);
        $to   = Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        $this->fromDateTime = $from->format('Y-m-d H:i:s');
        $this->toDateTime   = $to->format('Y-m-d H:i:s');
        $this->startDate    = $this->fromDateTime;
        $this->endDate      = $this->toDateTime;
    }

    public function totalHours(): int
    {
        return Carbon::parse($this->startDate)->diffInHours(Carbon::parse($this->endDate));
    }
}