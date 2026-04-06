<?php

namespace App\Repositories\General\Reports;
use App\Interfaces\General\Reports\AreaBasedInterface;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\OrderReport;
use App\OrderStatus;
use Carbon\Carbon;

class AreaBasedInterfaceRepository implements AreaBasedInterface
{
    public function getAreaBasedReports($request, $perPage)
    {
        $region = $request->region;
        $area = $request->area;

        $fromDate = $request->from_date
            ? Carbon::parse($request->from_date)->setTime(6,0,0)
            : now()->subDays(6)->setTime(6,0,0);

        $toDate = $request->to_date
            ? Carbon::parse($request->to_date)->addDay()->setTime(5,59,59)
            : now()->addDay()->setTime(5,59,59);

        $totalOrders = OrderReport::query()
            ->leftJoin('client_shops','client_shops.id','order_reports.shop_id')
            ->leftJoin('zones','zones.id','client_shops.zone_id')
            ->leftJoin('regions','regions.id','zones.region_id')
            ->leftJoin('quadrants','quadrants.id','regions.quadrant_id')
            ->whereBetween('order_reports.final_status_at',[$fromDate,$toDate])
            ->where('order_reports.status_id',OrderStatus::DELIVERED)
            ->when($region,fn($q)=>$q->where('quadrants.id',$region))
            ->when($area,fn($q)=>$q->where('regions.id',$area))
            ->count();

        return OrderReport::query()
            ->leftJoin('client_shops','client_shops.id','order_reports.shop_id')
            ->leftJoin('zones','zones.id','client_shops.zone_id')
            ->leftJoin('regions','regions.id','zones.region_id')
            ->leftJoin('quadrants','quadrants.id','regions.quadrant_id')
            ->whereBetween('order_reports.final_status_at',[$fromDate,$toDate])
            ->where('order_reports.status_id',OrderStatus::DELIVERED)
            ->when($region,fn($q)=>$q->where('quadrants.id',$region))
            ->when($area,fn($q)=>$q->where('regions.id',$area))
            ->groupBy('regions.id')
            ->select([
                'regions.id as area_id',
                'regions.name as area_name',
                DB::raw('COUNT(DISTINCT zones.id) as total_zones'),
                DB::raw('COUNT(DISTINCT order_reports.id) as total_orders'),
                DB::raw('COUNT(DISTINCT order_reports.captain_id) as courier_count'),
                DB::raw('COUNT(DISTINCT order_reports.client_id) as client_count'),
                DB::raw('COUNT(DISTINCT order_reports.shop_id) as branch_count'),
                DB::raw('ROUND(COUNT(order_reports.id) / NULLIF(COUNT(DISTINCT order_reports.captain_id),0),2) as avg_orders_per_captain'),
                DB::raw("ROUND((COUNT(order_reports.id) / $totalOrders) * 100,2) as area_weight_percentage"),
            ])
            ->paginate($perPage);
    }

    public function getAreaBasedTotals($request)
    {
        return OrderReport::query()
            ->leftJoin('client_shops','client_shops.id','order_reports.shop_id')
            ->leftJoin('zones','zones.id','client_shops.zone_id')
            ->leftJoin('regions','regions.id','zones.region_id')
            ->leftJoin('quadrants','quadrants.id','regions.quadrant_id')
            ->selectRaw('
                COUNT(DISTINCT zones.id) as zone_count,
                COUNT(DISTINCT order_reports.client_id) as client_count,
                COUNT(DISTINCT order_reports.shop_id) as branch_count,
                COUNT(DISTINCT order_reports.captain_id) as courier_count,
                COUNT(DISTINCT order_reports.id) as order_count
            ')
            ->first();
    }

}