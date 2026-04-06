<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\OrderReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GeographicalAreaLevelReportExport extends QueueExport
{
    protected int $chunk = 2000;

    protected string $file_name = 'geographical-area-level-report';

    public $timeout = 100000;

    public function data(): array
    {
        $data = [];
        $area_report_datas = $this->getData();
        foreach ($area_report_datas as $area) {
            $data[] = [
                $area->area_name ?? 'N/A',
                $area->total_zones ?? 0,
                $area->client_count ?? 0,
                $area->branch_count ?? 0,
                $area->total_orders ?? 0,
                $area->courier_count ?? 0,
                $area->area_weight_percentage ?? '0%',
                $area->avg_orders_per_captain ?? 0,
            ];
        }
        return $data;
    }

    public function headers(): array
    {
        return [
            'Area Name',
            'Zone',
            'Clients',
            'Shops',
            'Orders',
            'Captains',
            'Area Weight',
            'Average Order / Captain',
        ];
    }

    public function getData()
    {
        $request = $this->export->filters;

        $region = isset($request['region']) ? $request['region'] : null;
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
            ->when($region, function ($query, $region) {
                $query->where('regions.id', $region);
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
            ->when($region, function ($query, $region) {
                $query->where('regions.id', $region);
            })
            ->when($area, function ($query, $area) {
                $query->where('quadrants.id', $area);
            })
            ->groupBy('quadrants.id')
            ->select([
                'quadrants.id as area_id',
                'quadrants.name as area_name',
                DB::raw('COUNT(DISTINCT zones.id) as total_zones'),
                DB::raw('COUNT(DISTINCT order_reports.id) as total_orders'),
                DB::raw('COUNT(DISTINCT order_reports.captain_id) as courier_count'),
                DB::raw('COUNT(DISTINCT order_reports.client_id) as client_count'),
                DB::raw('COUNT(DISTINCT order_reports.shop_id) as branch_count'),
                DB::raw('ROUND(COUNT(order_reports.id) / NULLIF(COUNT(DISTINCT order_reports.captain_id), 0), 2) as avg_orders_per_captain'),
                DB::raw("ROUND((COUNT(order_reports.id) / $totalOrdersAllAreas) * 100, 2) as area_weight_percentage"),
            ])
            ->orderByRaw('COUNT(DISTINCT order_reports.id) DESC')
            ->limit($this->chunk)
            ->offset($this->chunk * $this->export->page_done ?? 0)
            ->get();

        return $reports;
    }

    public function count(): int
    {
        $request = $this->export->filters;

        $region = isset($request['region']) ? $request['region'] : null;
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
            ->when($region, function ($query, $region) {
                $query->where('regions.id', $region);
            })
            ->when($area, function ($query, $area) {
                $query->where('quadrants.id', $area);
            })
            ->groupBy('quadrants.id')
            ->count();

    }

}
