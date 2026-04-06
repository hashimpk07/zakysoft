<?php

namespace App\Services\General\Reports\KPIReports;

use App\Interfaces\General\Reports\GeographicalInterface;
use Carbon\Carbon;

final class GeographicalReportService
{
   
    public function __construct(protected readonly GeographicalInterface $interface) {}

    public function getGeographical($request, $perPage)
    {
        $zone = $request->zone;

        $fromDate = $request->from_date
            ? Carbon::parse($request->from_date)->startOfDay()
            : now()->subDays(6);

        $toDate = $request->to_date
            ? Carbon::parse($request->to_date)->endOfDay()
            : now()->endOfDay();

        $fromDate = Carbon::parse($fromDate)->setTime(6,0,0);
        $toDate = Carbon::parse($toDate)->addDay()->setTime(5,59,59);

        $totalDays = $fromDate->diffInDays($toDate) + 1;

        return $this->interface->getGeographicalReports($zone,$fromDate,$toDate,$totalDays,$perPage);
    
    }
}