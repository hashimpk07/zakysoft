<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\OrderReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GeographicalZoneLevelReportExport extends QueueExport
{
    protected int $chunk = 2000;

    protected string $file_name = 'geographical-zone-level-report';

    public $timeout = 100000;

    public function data(): array
    {
        $data = [];
        $area_report_datas = $this->getData();
        foreach ($area_report_datas as $area) {
            $data[] = [
                $area->zone_name ?? 'N/A',
                $area->client_count ?? 'N/A',
                $area->branch_count ?? 0,
                $area->total_orders,
                $area->courier_count,
                $area->zone_weight_percentage,
                $area->avg_orders_per_captain,
            ];
        }

        return $data;
    }

    public function headers(): array
    {
        return [
            'Zone Name',
            'Clients',
            'Shops',
            'Orders',
            'Captains',
            'Zone Weight',
            'Average Order / Captain',
        ];
    }

    public function getData()
    {
        $request = $this->export->filters;

        $zone = isset($request['zone']) ? $request['zone'] : null;
        $area = isset($request['area']) ? $request['area'] : null;

        $fromDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'])->startOfDay()
            : now()->startOfMonth()->startOfDay();

        $toDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'])->endOfDay()
            : now()->endOfDay();

        $fromDate = Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDate = Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        $totalOrdersAllAreas = OrderReport::query()
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones', 'zones.id', 'client_shops.zone_id')
            ->leftJoin('regions', 'regions.id', 'zones.region_id')
            ->leftJoin('quadrants', 'quadrants.id', 'regions.quadrant_id')
            ->whereBetween('order_reports.final_status_at', [$fromDate, $toDate])
            ->finishedOrders()
            ->belongsToMe()
            ->when($zone, function ($query, $zone) {
                $query->where('zones.id', $zone);
            })
            ->when($area, function ($query, $area) {
                $query->where('quadrants.id', $area);
            })
            ->count('order_reports.id');

        $reports = OrderReport::query()
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones', 'zones.id', 'client_shops.zone_id')
            ->leftJoin('regions', 'regions.id', 'zones.region_id')
            ->leftJoin('quadrants', 'quadrants.id', 'regions.quadrant_id')
            ->whereBetween('order_reports.final_status_at', [$fromDate, $toDate])
            ->finishedOrders()
            ->belongsToMe()
            ->when($zone, function ($query, $zone) {
                $query->where('zones.id', $zone);
            })
            ->when($area, function ($query, $area) {
                $query->where('quadrants.id', $area);
            })
            ->groupBy('zones.id')
            ->select([
                'zones.id as zone_id',
                'zones.name as zone_name',
                DB::raw('COUNT(DISTINCT zones.id) as total_zones'),
                DB::raw('COUNT(DISTINCT order_reports.id) as total_orders'),
                DB::raw('COUNT(DISTINCT order_reports.captain_id) as courier_count'),
                DB::raw('COUNT(DISTINCT order_reports.client_id) as client_count'),
                DB::raw('COUNT(DISTINCT order_reports.shop_id) as branch_count'),
                DB::raw('ROUND(COUNT(order_reports.id) / NULLIF(COUNT(DISTINCT order_reports.captain_id), 0), 2) as avg_orders_per_captain'),
                DB::raw("ROUND((COUNT(order_reports.id) / $totalOrdersAllAreas) * 100, 2) as zone_weight_percentage"),
            ])
            ->orderBy('total_orders', 'desc')
            ->limit($this->chunk)
            ->offset($this->chunk * $this->export->page_done ?? 0)
            ->get();


        return $reports;
    }

    public function count(): int
    {
        $request = $this->export->filters;

        $zone = isset($request['zone']) ? $request['zone'] : null;
        $area = isset($request['area']) ? $request['area'] : null;

        $fromDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'])->startOfDay()
            : now()->startOfMonth()->startOfDay();

        $toDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'])->endOfDay()
            : now()->endOfDay();


        $fromDate = Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDate = Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        return OrderReport::query()
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones', 'zones.id', 'client_shops.zone_id')
            ->leftJoin('regions', 'regions.id', 'zones.region_id')
            ->leftJoin('quadrants', 'quadrants.id', 'regions.quadrant_id')
            ->whereBetween('order_reports.final_status_at', [$fromDate, $toDate])
            ->finishedOrders()
            ->belongsToMe()
            ->when($zone, function ($query, $zone) {
                $query->where('zones.id', $zone);
            })
            ->when($area, function ($query, $area) {
                $query->where('quadrants.id', $area);
            })
            ->groupBy('zones.id')
            ->count();
    }

}
