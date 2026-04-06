<?php

namespace App\Repositories\General\Reports;
use App\Interfaces\General\Reports\RegionBasedInterface;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use App\OrderReport;
use App\OrderStatus;
use Carbon\Carbon;


class RegionBasedInterfaceRepository implements RegionBasedInterface
{
    private function baseQuery($request)
    {

        $region = $request->region;

        $fromDate = $request->from_date
            ? Carbon::parse($request->from_date)->setTime(6,0,0)
            : now()->subDays(6)->setTime(6,0,0);

        $toDate = $request->to_date
            ? Carbon::parse($request->to_date)->addDay()->setTime(5,59,59)
            : now()->addDay()->setTime(5,59,59);

        return OrderReport::query()
            ->leftJoin('client_shops','client_shops.id','order_reports.shop_id')
            ->leftJoin('zones','zones.id','client_shops.zone_id')
            ->leftJoin('regions','regions.id','zones.region_id')
            ->leftJoin('quadrants','quadrants.id','regions.quadrant_id')
            ->whereNotNull('quadrants.id')
            ->whereBetween('order_reports.final_status_at',[$fromDate,$toDate])
            ->where('order_reports.status_id',OrderStatus::DELIVERED)
            ->excludeQuadrants('quadrants.id')
            ->belongsToMe()
            ->when($region,fn($q)=>$q->where('quadrants.id',$region));
    }


    public function getRegionTotalOrders($request)
    {
        return $this->baseQuery($request)
            ->distinct('order_id')
            ->count('order_id');
    }


    public function getRegionTotalsRaw($request)
    {
        return $this->baseQuery($request)
            ->selectRaw("
                COUNT(DISTINCT regions.id) as area_count,
                COUNT(DISTINCT zones.id) as zone_sum,
                COUNT(DISTINCT order_reports.client_id) as client_sum,
                COUNT(DISTINCT order_reports.shop_id) as branch_sum,
                COUNT(DISTINCT order_reports.captain_id) as courier_sum,
                COUNT(DISTINCT order_reports.order_id) as order_count_sum
            ")
            ->first();
    }


    public function getRegionReports($request,$perPage)
    {

        $totalOrders = $this->getRegionTotalOrders($request);

        return $this->baseQuery($request)
            ->groupBy('quadrants.id')
            ->select([
                'quadrants.id as region_id',
                'quadrants.name as region_name',
                DB::raw('COUNT(DISTINCT regions.id) as area'),
                DB::raw('COUNT(DISTINCT zones.id) as zone_count'),
                DB::raw('COUNT(DISTINCT order_reports.client_id) as client_count'),
                DB::raw('COUNT(DISTINCT order_reports.shop_id) as branch_count'),
                DB::raw('COUNT(DISTINCT order_reports.captain_id) as courier_count'),
                DB::raw('COUNT(DISTINCT order_reports.order_id) as order_count'),
                DB::raw("ROUND(COUNT(DISTINCT order_reports.order_id)*100.0/{$totalOrders},2) as order_percentage"),
                DB::raw("
                    ROUND(
                        COUNT(DISTINCT order_reports.order_id) /
                        NULLIF(COUNT(DISTINCT order_reports.captain_id),0),2
                    ) as order_per_courier
                ")
            ])
            ->orderBy('quadrants.name','asc')
            ->paginate($perPage);
    }


    public function getRegionOrderPerCourierAverage($request)
    {
        return $this->baseQuery($request)
            ->groupBy('quadrants.id')
            ->selectRaw("
                ROUND(
                    COUNT(DISTINCT order_reports.order_id) /
                    NULLIF(COUNT(DISTINCT order_reports.captain_id),0),2
                ) as order_per_courier
            ")
            ->pluck('order_per_courier')
            ->avg();
    }


    public function getRegionOrderPercentageSum($request,$totalOrders)
    {
        return $this->baseQuery($request)
            ->groupBy('quadrants.id')
            ->selectRaw("
                (COUNT(DISTINCT order_reports.order_id)*100.0/{$totalOrders})
                as order_percentage
            ")
            ->pluck('order_percentage')
            ->sum();
    }
}