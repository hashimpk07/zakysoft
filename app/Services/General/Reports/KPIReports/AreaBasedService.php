<?php

namespace App\Services\General\Reports\KPIReports;

use App\Interfaces\General\Reports\AreaBasedInterface;
use Carbon\Carbon;

final class AreaBasedService
{
   
    public function __construct(protected readonly AreaBasedInterface $interface) {}

    public function getAreaBased($request, $perPage)
    {
        $reports = $this->interface->getAreaBasedReports($request, $perPage);

        $totals = $this->interface->getAreaBasedTotals($request);

        return [
            'reports' => $reports,
            'totals' => $totals
        ];
    }
}