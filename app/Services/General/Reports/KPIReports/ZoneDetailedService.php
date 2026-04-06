<?php

namespace App\Services\General\Reports\KPIReports;

use App\Interfaces\General\Reports\ZoneInterface;
use Carbon\Carbon;


final class ZoneDetailedService
{
   
    public function __construct(protected readonly ZoneInterface $interface) {}

    public function getZoneDetailed($request, int $perPage)
    {
        $fromDate = $request->from_date
            ? Carbon::parse($request->from_date)->setTime(6,0,0)
            : now()->subDays(6)->setTime(6,0,0);

        $toDate = $request->to_date
            ? Carbon::parse($request->to_date)->addDay()->setTime(5,59,59)
            : now()->addDay()->setTime(5,59,59);

        $filters = [
            'client' => $request->client,
            'region' => $request->region,
            'area'   => $request->area,
            'zone'   => $request->zone,
            'fromDate' => $fromDate,
            'toDate'   => $toDate
        ];

        $totalOrderCount = $this->interface->getTotalOrderCount($filters);

        $reports =  $this->interface->getZoneDetailedReports($filters, $totalOrderCount,$perPage);

        $collection = collect($reports->items());

        $totals = [
            'total_shop' => $collection->sum('shop'),
            'total_orders' => $collection->sum('orders'),
            'total_captain' => $collection->sum('captain'),
            'total_client_weight' => round($collection->sum('client_weight'),2),
            'avg_ord_cap' => round($collection->avg('avg_ord_cap'),2),
        ];

        return [
            'reports' => $reports,
            'totals' => $totals
        ];
    }
    
   
}
