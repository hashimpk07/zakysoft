<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\OrderReport;
use App\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ZoneDetailedReportExport extends QueueExport
{
    protected int $chunk = 2000;

    protected string $file_name = 'zone-detailed-report';

    public $timeout = 100000;

    public function data(): array
    {
        $data = [];
        $zone_detailed_report_datas = $this->getData();
        foreach ($zone_detailed_report_datas as $zone_detailed) {
            $data[] = [
                $zone_detailed->date ?? 'N/A',
                $zone_detailed->region_name ?? 'N/A',
                $zone_detailed->area_name ?? 'N/A',
                $zone_detailed->zone_name ?? 'N/A',
                $zone_detailed->user_name ?? 'N/A',
                $zone_detailed->branch_count ?? 0,
                $zone_detailed->order_count ?? 0,
                $zone_detailed->courier_count ?? 0,
                $zone_detailed->client_weight ?? 0,
                $zone_detailed->order_per_courier ?? 0,
            ];
        }

        return $data;
    }

    public function headers(): array
    {
        return [
            'Date',
            'Region',
            'Area',
            'Zone',
            'Clients',
            'Shop',
            'Orders',
            'Captains',
            'Client Weight',
            'AVRG ORD/CAP',
        ];
    }

    public function getData()
    {
        $request = $this->export->filters;

        $region = isset($request['region']) ? $request['region'] : null;
        $client = isset($request['client']) ? $request['client'] : null;
        $area = isset($request['area']) ? $request['area'] : null;
        $zone = isset($request['zone']) ? $request['zone'] : null;

        $fromDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'])->startOfDay()
            : now()->subDays(6)->startOfDay();

        $toDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'])->endOfDay()
            : now()->addDay()->endOfDay();

        $fromDate = Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDate = Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        $totalOrderCount = OrderReport::query()
            ->leftJoin('clients', 'clients.id', 'order_reports.client_id')
            ->leftJoin('users', 'users.id', 'clients.user_id')
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones', 'zones.id', 'client_shops.zone_id')
            ->leftJoin('regions', 'regions.id', 'zones.region_id')
            ->leftJoin('quadrants', 'quadrants.id', 'regions.quadrant_id')
            ->whereNotNull('order_reports.client_id')
            ->whereBetween('final_status_at', [$fromDate, $toDate])
            // ->finishedOrders()
            ->where('order_reports.status_id', OrderStatus::DELIVERED)
            ->excludeQuadrants('quadrants.id')
            ->belongsToMe()
            ->when($client, function ($query, $client) {
                $query->where('order_reports.client_id', $client);
            })
            ->when($region, function ($query, $region) {
                // $query->where('regions.id', $region);
                $query->where('quadrants.id', $region);
            })
            ->when($area, function ($query, $area) {
                // $query->where('quadrants.id', $area);
                $query->where('regions.id', $area);
            })
            ->when($zone, function ($query, $zone) {
                $query->where('zones.id', $zone);
            })
            ->distinct('order_id')
            ->count('order_id');



        $reports = OrderReport::query()
            ->leftJoin('clients', 'clients.id', 'order_reports.client_id')
            ->leftJoin('users', 'users.id', 'clients.user_id')
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones', 'zones.id', 'client_shops.zone_id')
            ->leftJoin('regions', 'regions.id', 'zones.region_id')
            ->leftJoin('quadrants', 'quadrants.id', 'regions.quadrant_id')
            ->whereNotNull('order_reports.client_id')
            ->whereBetween('order_reports.final_status_at', [$fromDate, $toDate])
            // ->finishedOrders()
            ->where('order_reports.status_id', OrderStatus::DELIVERED)
            ->excludeQuadrants('quadrants.id')
            ->belongsToMe()
            ->when($client, function ($query, $client) {
                $query->where('order_reports.client_id', $client);
            })
            ->when($region, function ($query, $region) {
                // $query->where('regions.id', $region);
                $query->where('quadrants.id', $region);
            })
            ->when($area, function ($query, $area) {
                // $query->where('quadrants.id', $area);
                $query->where('regions.id', $area);
            })
            ->when($zone, function ($query, $zone) {
                $query->where('zones.id', $zone);
            })
            ->groupBy('regions.name', 'quadrants.name', 'zones.name', 'order_reports.client_id', 'users.id', 'users.name', DB::raw('DATE(order_reports.final_status_at)'))
            ->select([
                DB::raw('DATE(order_reports.final_status_at) as date'),
                'quadrants.name as region_name',
                'regions.name as area_name',
                'zones.name as zone_name',
                'users.id as user_id',
                'users.name as user_name',
                DB::raw('COUNT(DISTINCT order_reports.shop_id) as branch_count'),
                DB::raw('COUNT(DISTINCT order_reports.captain_id) as courier_count'),
                DB::raw('COUNT(DISTINCT order_reports.order_id) as order_count'),
                DB::raw("ROUND(COUNT(DISTINCT order_reports.order_id) * 100.0 / {$totalOrderCount}, 2) as client_weight"),
                DB::raw("
                    CASE 
                        WHEN COUNT(DISTINCT order_reports.captain_id) = 0 THEN 0
                        ELSE ROUND(COUNT(DISTINCT order_reports.order_id) * 1.0 / COUNT(DISTINCT order_reports.captain_id), 2)
                    END as order_per_courier
                ")

            ])
            ->orderBy('users.name', 'asc')
            ->limit($this->chunk)
            ->offset($this->chunk * $this->export->page_done ?? 0)
            ->get();


        return $reports;
    }

    public function count(): int
    {
        $request = $this->export->filters;

        $region = isset($request['region']) ? $request['region'] : null;
        $client = isset($request['client']) ? $request['client'] : null;
        $area = isset($request['area']) ? $request['area'] : null;
        $zone = isset($request['zone']) ? $request['zone'] : null;

        $fromDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'])->startOfDay()
            : now()->subDays(6)->startOfDay();

        $toDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'])->endOfDay()
            : now()->endOfDay();

        $fromDate = Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDate = Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);


        $totalOrderCount = OrderReport::query()
            ->leftJoin('clients', 'clients.id', 'order_reports.client_id')
            ->leftJoin('users', 'users.id', 'clients.user_id')
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones', 'zones.id', 'client_shops.zone_id')
            ->leftJoin('regions', 'regions.id', 'zones.region_id')
            ->leftJoin('quadrants', 'quadrants.id', 'regions.quadrant_id')
            ->whereNotNull('order_reports.client_id')
            ->whereBetween('final_status_at', [$fromDate, $toDate])
            // ->finishedOrders()
            ->where('order_reports.status_id', OrderStatus::DELIVERED)
            ->excludeQuadrants('quadrants.id')
            ->when($client, function ($query, $client) {
                $query->where('order_reports.client_id', $client);
            })
            ->when($region, function ($query, $region) {
                // $query->where('regions.id', $region);
                $query->where('quadrants.id', $region);
            })
            ->when($area, function ($query, $area) {
                // $query->where('quadrants.id', $area);
                $query->where('regions.id', $area);
            })
            ->when($zone, function ($query, $zone) {
                $query->where('zones.id', $zone);
            })
            ->belongsToMe()
            ->distinct('order_id')
            ->count('order_id');

        return OrderReport::query()
            ->leftJoin('clients', 'clients.id', 'order_reports.client_id')
            ->leftJoin('users', 'users.id', 'clients.user_id')
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones', 'zones.id', 'client_shops.zone_id')
            ->leftJoin('regions', 'regions.id', 'zones.region_id')
            ->leftJoin('quadrants', 'quadrants.id', 'regions.quadrant_id')
            ->whereNotNull('order_reports.client_id')
            ->whereBetween('order_reports.final_status_at', [$fromDate, $toDate])
            // ->finishedOrders()
            ->where('order_reports.status_id', OrderStatus::DELIVERED)
            ->excludeQuadrants('quadrants.id')
            ->belongsToMe()
            ->when($client, function ($query, $client) {
                $query->where('order_reports.client_id', $client);
            })
            ->when($region, function ($query, $region) {
                // $query->where('regions.id', $region);
                $query->where('quadrants.id', $region);
            })
            ->when($area, function ($query, $area) {
                // $query->where('quadrants.id', $area);
                $query->where('regions.id', $area);
            })
            ->when($zone, function ($query, $zone) {
                $query->where('zones.id', $zone);
            })
            ->groupBy('order_reports.client_id')
            ->select([
                'order_reports.final_status_at as date',
                'quadrants.name as region_name',
                'regions.name as area_name',
                'zones.name as zone_name',
                'users.id as user_id',
                'users.name as user_name',
                DB::raw('COUNT(DISTINCT order_reports.shop_id) as branch_count'),
                DB::raw('COUNT(DISTINCT order_reports.captain_id) as courier_count'),
                DB::raw('COUNT(DISTINCT order_reports.order_id) as order_count'),
                DB::raw("ROUND(COUNT(DISTINCT order_reports.order_id) * 100.0 / {$totalOrderCount}, 2) as client_weight"),
                DB::raw("
                    CASE 
                        WHEN COUNT(DISTINCT order_reports.captain_id) = 0 THEN 0
                        ELSE ROUND(COUNT(DISTINCT order_reports.order_id) * 1.0 / COUNT(DISTINCT order_reports.captain_id), 2)
                    END as order_per_courier
                ")

            ])
            ->count();


    }

}
