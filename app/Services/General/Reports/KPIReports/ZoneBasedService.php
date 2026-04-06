<?php

namespace App\Services\General\Reports\KPIReports;

use App\Interfaces\General\Reports\ZoneInterface;
use Carbon\Carbon;

final class ZoneBasedService
{
   
    public function __construct(protected readonly ZoneInterface $interface) {}

    public function getZoneBased($request,$perPage)
    {

        $from = $request->from_date
            ? Carbon::parse($request->from_date)->setTime(6,0)
            : now()->subDays(6)->setTime(6,0,0);

        $to = $request->to_date
            ? Carbon::parse($request->to_date)->addDay()->setTime(5,59,59)
            : now()->addDay()->setTime(5,59,59);

        $filters = [
            'zone'=>$request->zone,
            'area'=>$request->area,
            'region'=>$request->region,
            'from'=>$from,
            'to'=>$to
        ];

        $result = $this->interface->getZoneBasedReports($filters,$perPage);

        $collection = collect($result['reports']->items());

        $totals = [
            'zone_count'=>$collection->count(),
            'client_count'=>$collection->sum('clients'),
            'branch_count'=>$collection->sum('shops'),
            'order_count'=>$collection->sum('orders'),
            'courier_count'=>$collection->sum('captain'),
            'zone_weight_percentage'=>$collection->sum('zone_weight_percentage'),
            'avg_orders_per_captain'=>round($collection->avg('avg_orders_per_captain'),2)
        ];

        return [
            'reports'=>$result['reports'],
            'totals'=>$totals
        ];
    }
   
}
