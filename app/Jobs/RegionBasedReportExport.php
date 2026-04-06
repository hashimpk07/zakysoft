<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\OrderReport;
use App\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RegionBasedReportExport extends QueueExport
{
    protected int $chunk = 2000;

    protected string $file_name = 'region-based-report';

    public $timeout = 100000;

    public function data(): array
    {
        $data = [];
        $region_based_report_datas = $this->getData();
        foreach ($region_based_report_datas as $region) {
            $data[] = [
                $region->region_name ?? 'N/A',
                $region->area ?? 'N/A',
                $region->zone_count ?? 0,
                $region->client_count,
                $region->branch_count,
                $region->order_count,
                $region->courier_count,
                $region->order_percentage,
                $region->order_per_courier,
            ];
        }

        return $data;
    }

    public function headers(): array
    {
        return [
            'Region Name',
            'Area',
            'Zone',
            'Client',
            'Shop',
            'Orders',
            'Captain',
            'Region Weight',
            'AVRG ORD/CAP',
        ];
    }

    public function getData()
    {
        $request = $this->export->filters;


        $region = isset($request['region']) ? $request['region'] : null;

        $fromDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'])->startOfDay()
            : now()->subDays(6)->startOfDay();

        $toDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'])->endOfDay()
            : now()->endOfDay();

        $fromDate = Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDate = Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        $totalOrderCount = OrderReport::query()
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones', 'zones.id', 'client_shops.zone_id')
            ->leftJoin('regions', 'regions.id', 'zones.region_id')
            ->leftJoin('quadrants', 'quadrants.id', 'regions.quadrant_id')
            ->whereNotNull('regions.id')
            ->whereBetween('final_status_at', [$fromDate, $toDate])
            // ->finishedOrders()
            ->where('order_reports.status_id', OrderStatus::DELIVERED)
            ->excludeQuadrants('quadrants.id')
            ->belongsToMe()
            ->distinct('order_id')
            ->count('order_id');




        $reports = OrderReport::query()
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones', 'zones.id', 'client_shops.zone_id')
            ->leftJoin('regions', 'regions.id', 'zones.region_id')
            ->leftJoin('quadrants', 'quadrants.id', 'regions.quadrant_id')
            ->whereNotNull('quadrants.id')
            ->whereBetween('order_reports.final_status_at', [$fromDate, $toDate])
            // ->finishedOrders()
            ->where('order_reports.status_id', OrderStatus::DELIVERED)
            ->excludeQuadrants('quadrants.id')
            ->belongsToMe()
            ->when($region, function ($query, $region) {
                $query->where('quadrants.id', $region);
            })
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
                DB::raw("ROUND(COUNT(DISTINCT order_reports.order_id) * 100.0 / {$totalOrderCount}, 2) as order_percentage"),
                DB::raw("
                    CASE 
                        WHEN COUNT(DISTINCT order_reports.captain_id) = 0 THEN 0
                        ELSE ROUND(COUNT(DISTINCT order_reports.order_id) * 1.0 / COUNT(DISTINCT order_reports.captain_id), 2)
                    END as order_per_courier
                ")

            ])
            ->orderBy('quadrants.name', 'asc')
            ->limit($this->chunk)
            ->offset($this->chunk * $this->export->page_done ?? 0)
            ->get();

        return $reports;
    }

    public function count(): int
    {
        $request = $this->export->filters;

        $region = isset($request['region']) ? $request['region'] : null;

        $fromDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'])->startOfDay()
            : now()->subDays(6)->startOfDay();

        $toDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'])->endOfDay()
            : now()->endOfDay();

        $fromDate = Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDate = Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);


        $totalOrderCount = OrderReport::query()
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones', 'zones.id', 'client_shops.zone_id')
            ->leftJoin('regions', 'regions.id', 'zones.region_id')
            ->leftJoin('quadrants', 'quadrants.id', 'regions.quadrant_id')
            ->whereNotNull('quadrants.id')
            ->whereBetween('final_status_at', [$fromDate, $toDate])
            // ->finishedOrders()
            ->where('order_reports.status_id', OrderStatus::DELIVERED)
            ->excludeQuadrants('quadrants.id')
            ->belongsToMe()
            ->distinct('order_id')
            ->count('order_id');

        return OrderReport::query()
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones', 'zones.id', 'client_shops.zone_id')
            ->leftJoin('regions', 'regions.id', 'zones.region_id')
            ->leftJoin('quadrants', 'quadrants.id', 'regions.quadrant_id')
            ->whereNotNull('regions.id')
            ->whereBetween('order_reports.final_status_at', [$fromDate, $toDate])
            // ->finishedOrders()
            ->where('order_reports.status_id', OrderStatus::DELIVERED)
            ->excludeQuadrants('quadrants.id')
            ->belongsToMe()
            ->when($region, function ($query, $region) {
                $query->where('quadrants.id', $region);
            })
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
                DB::raw("ROUND(COUNT(DISTINCT order_reports.order_id) * 100.0 / {$totalOrderCount}, 2) as order_percentage"),
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
