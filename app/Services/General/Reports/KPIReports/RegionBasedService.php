<?php

namespace App\Services\General\Reports\KPIReports;

use App\Interfaces\General\Reports\RegionBasedInterface;
use Carbon\Carbon;

final class RegionBasedService
{
   
    public function __construct(protected readonly RegionBasedInterface $interface) {}

    public function getRegionBased($request, $perPage)
    {
        $reports = $this->interface->getRegionReports($request,$perPage);
        $totalsRaw = $this->interface->getRegionTotalsRaw($request);
        $totalOrders = $this->interface->getRegionTotalOrders($request);
        $orderPerCourierAvg = $this->interface->getRegionOrderPerCourierAverage($request);
        $orderPercentage = $this->interface->getRegionOrderPercentageSum($request,$totalOrders);

        $totals = [
            'area' => $totalsRaw->area_count ?? 0,
            'zone_count' => $totalsRaw->zone_sum ?? 0,
            'client_count' => $totalsRaw->client_sum ?? 0,
            'branch_count' => $totalsRaw->branch_sum ?? 0,
            'order_count' => $totalsRaw->order_count_sum ?? 0,
            'courier_count' => $totalsRaw->courier_sum ?? 0,
            'order_percentage' => round($orderPercentage,2),
            'order_per_courier' => round($orderPerCourierAvg,2)
        ];

        return [
            'reports' => $reports,
            'totals' => $totals
        ];
    }
}