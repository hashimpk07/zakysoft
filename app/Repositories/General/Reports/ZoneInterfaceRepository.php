<?php

namespace App\Repositories\General\Reports;
use App\Interfaces\General\Reports\ZoneInterface;

use App\asset;
use App\AssetCategory;
use Illuminate\Support\Collection;

use App\Client;
use App\OrderReport;
use App\OrderStatus;

use Illuminate\Support\Facades\DB;

class ZoneInterfaceRepository implements ZoneInterface
{
    public function getTotalOrderCount(array $filters)
    {
        return OrderReport::query()
            ->leftJoin('clients', 'clients.id', 'order_reports.client_id')
            ->leftJoin('users', 'users.id', 'clients.user_id')
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones', 'zones.id', 'client_shops.zone_id')
            ->leftJoin('regions', 'regions.id', 'zones.region_id')
            ->leftJoin('quadrants', 'quadrants.id', 'regions.quadrant_id')

            ->whereNotNull('order_reports.client_id')
            ->whereBetween('final_status_at', [$filters['fromDate'], $filters['toDate']])
            ->where('order_reports.status_id', OrderStatus::DELIVERED)

            ->when($filters['client'], fn($q,$v)=>$q->where('order_reports.client_id',$v))
            ->when($filters['region'], fn($q,$v)=>$q->where('quadrants.id',$v))
            ->when($filters['area'], fn($q,$v)=>$q->where('regions.id',$v))
            ->when($filters['zone'], fn($q,$v)=>$q->where('zones.id',$v))

            ->distinct('order_id')
            ->count('order_id');
    }


    public function getZoneDetailedReports(array $filters, $totalOrderCount,$perPage)
    {
        return OrderReport::query()
            ->leftJoin('clients', 'clients.id', 'order_reports.client_id')
            ->leftJoin('users', 'users.id', 'clients.user_id')
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones', 'zones.id', 'client_shops.zone_id')
            ->leftJoin('regions', 'regions.id', 'zones.region_id')
            ->leftJoin('quadrants', 'quadrants.id', 'regions.quadrant_id')

            ->whereNotNull('order_reports.client_id')
            ->whereBetween('order_reports.final_status_at', [$filters['fromDate'], $filters['toDate']])
            ->where('order_reports.status_id', OrderStatus::DELIVERED)

            ->when($filters['client'], fn($q,$v)=>$q->where('order_reports.client_id',$v))
            ->when($filters['region'], fn($q,$v)=>$q->where('quadrants.id',$v))
            ->when($filters['area'], fn($q,$v)=>$q->where('regions.id',$v))
            ->when($filters['zone'], fn($q,$v)=>$q->where('zones.id',$v))

            ->groupBy('order_reports.client_id')

            ->select([
                'users.name as client',
                DB::raw('COUNT(DISTINCT order_reports.shop_id) as shop'),
                DB::raw('COUNT(DISTINCT order_reports.order_id) as orders'),
                DB::raw('COUNT(DISTINCT order_reports.captain_id) as captain'),

                DB::raw("ROUND(COUNT(DISTINCT order_reports.order_id) * 100.0 / {$totalOrderCount}, 2) as client_weight"),

                DB::raw("
                    CASE 
                        WHEN COUNT(DISTINCT order_reports.captain_id)=0 
                        THEN 0
                        ELSE ROUND(
                            COUNT(DISTINCT order_reports.order_id) /
                            COUNT(DISTINCT order_reports.captain_id),2)
                    END as avg_ord_cap
                "),
            ])

            ->orderBy('users.name')
            ->paginate($perPage);
    }


    public function getZoneBasedReports($filters, $perPage)
    {
        $totalOrdersAllAreas = OrderReport::query()
            ->leftJoin('client_shops','client_shops.id','order_reports.shop_id')
            ->leftJoin('zones','zones.id','client_shops.zone_id')
            ->leftJoin('regions','regions.id','zones.region_id')
            ->leftJoin('quadrants','quadrants.id','regions.quadrant_id')

            ->whereNotNull('zones.id')
            ->whereBetween('order_reports.final_status_at',[$filters['from'],$filters['to']])
            ->where('order_reports.status_id',OrderStatus::DELIVERED)

            ->when($filters['zone'],fn($q)=>$q->where('zones.id',$filters['zone']))
            ->when($filters['area'],fn($q)=>$q->where('regions.id',$filters['area']))

            ->count('order_reports.id');


        $reports = OrderReport::query()

            ->leftJoin('client_shops','client_shops.id','order_reports.shop_id')
            ->leftJoin('zones','zones.id','client_shops.zone_id')
            ->leftJoin('regions','regions.id','zones.region_id')
            ->leftJoin('quadrants','quadrants.id','regions.quadrant_id')

            ->whereNotNull('zones.id')
            ->whereBetween('order_reports.final_status_at',[$filters['from'],$filters['to']])
            ->where('order_reports.status_id',OrderStatus::DELIVERED)

            ->when($filters['zone'],fn($q)=>$q->where('zones.id',$filters['zone']))
            ->when($filters['area'],fn($q)=>$q->where('regions.id',$filters['area']))

            ->groupBy('zones.id')

            ->select([
                'zones.id as zone_id',
                'zones.name as zone_name',

                DB::raw('COUNT(DISTINCT order_reports.order_id) as orders'),
                DB::raw('COUNT(DISTINCT order_reports.captain_id) as captain'),
                DB::raw('COUNT(DISTINCT order_reports.client_id) as clients'),
                DB::raw('COUNT(DISTINCT order_reports.shop_id) as shops'),

                DB::raw("
                    ROUND(
                        COUNT(order_reports.order_id) /
                        NULLIF(COUNT(DISTINCT order_reports.captain_id),0),
                        2
                    ) as avg_orders_per_captain
                "),

                DB::raw("
                    ROUND(
                        COUNT(order_reports.order_id) *100 / {$totalOrdersAllAreas},
                        2
                    ) as zone_weight_percentage
                ")
            ])

            ->orderByDesc('orders')

            ->paginate($perPage);

        return [
            'reports'=>$reports,
            'totalOrders'=>$totalOrdersAllAreas
        ];
    }
}