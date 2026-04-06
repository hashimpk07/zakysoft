<?php

namespace App\Repositories\General\Reports;
use App\Interfaces\General\Reports\GeographicalInterface;

use Illuminate\Support\Facades\DB;
use App\OrderReport;

class GeographicalInterfaceRepository implements GeographicalInterface
{
   
    public function getGeographicalReports($zone,$fromDate,$toDate,$totalDays,$perPage)
    {
        return OrderReport::query()
            ->leftJoin('client_shops','client_shops.id','order_reports.shop_id')
            ->leftJoin('zones','zones.id','client_shops.zone_id')
            ->leftJoin('regions','regions.id','zones.region_id')

            ->whereBetween('order_reports.final_status_at',[$fromDate,$toDate])
            ->finishedOrders()
            ->belongsToMe()

            ->when($zone,function($query,$zone){
                $query->where('zones.id',$zone);
            })

            ->groupBy('zones.id')

            ->select([
                'zones.id as zone_id',
                'zones.name as zone_name',
                'regions.name as area_name',

                DB::raw('COUNT(DISTINCT order_reports.id) as total_orders'),
                DB::raw("ROUND(COUNT(DISTINCT order_reports.id) / $totalDays,2) as avg_daily_orders"),
                DB::raw('COUNT(DISTINCT order_reports.client_id) as client_count'),
                DB::raw('COUNT(DISTINCT order_reports.shop_id) as branch_count'),
                DB::raw('COUNT(DISTINCT order_reports.captain_id) as courier_count'),

                DB::raw('AVG(TIMESTAMPDIFF(SECOND,start_ride_at,reached_shop_at)) as avg_arrival_time'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND,reached_shop_at,order_picked_at)) as avg_pickup_time'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND,shipped_at,reached_dest_at)) as avg_reached_time'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND,order_picked_at,reached_dest_at)) as avg_pickup_to_delivered'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND,order_created_at,final_status_at)) as avg_process_time'),

                DB::raw('AVG(shop_to_delivery_km) as avg_distance')
            ])

            ->orderBy('total_orders','desc')

            ->paginate($perPage);
    }
}