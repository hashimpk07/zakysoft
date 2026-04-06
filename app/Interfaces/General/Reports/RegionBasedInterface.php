<?php

namespace App\Interfaces\General\Reports;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

interface RegionBasedInterface
{
    public function getRegionReports($request, $perPage);
    public function getRegionTotalsRaw($request);
    public function getRegionTotalOrders($request);
    public function getRegionOrderPerCourierAverage($request);
    public function getRegionOrderPercentageSum($request, $totalOrders);
}