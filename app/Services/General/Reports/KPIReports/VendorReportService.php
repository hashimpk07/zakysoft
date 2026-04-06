<?php

namespace App\Services\General\Reports\KPIReports;

use App\Interfaces\General\Reports\VendorLevelInterface;
use Carbon\Carbon;

final class VendorReportService
{
   
    public function __construct(protected readonly VendorLevelInterface $interface) {}

    public function getVendorLevel($request, $perPage)
    {
        $fromInput = $request->get('from_date');
        $toInput = $request->get('to_date');

        if ($fromInput && $toInput) {

            $fromDate = Carbon::parse($fromInput)->startOfDay();
            $toDate = Carbon::parse($toInput)->endOfDay();

        } elseif (!$fromInput && $toInput) {

            $toDate = Carbon::parse($toInput)->endOfDay();
            $fromDate = $toDate->copy()->startOfDay();

        } elseif ($fromInput && !$toInput) {

            $fromDate = Carbon::parse($fromInput)->startOfDay();
            $toDate = now()->endOfDay();

        } else {

            $fromDate = now()->subDays(6);
            $toDate = now()->endOfDay();
        }

        $request->merge([
            'fromDate' => $fromDate,
            'toDate' => $toDate
        ]);

        return $this->interface->getVendorReports($request,$perPage);
    
    }
}