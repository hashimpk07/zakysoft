<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\OrderReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GeographicalLevelReportExport extends QueueExport
{
    protected int $chunk = 2000;

    protected string $file_name = 'geographical-level-report';

    public $timeout = 100000;

    public function data(): array
    {
        $data = [];
        $area_report_datas = $this->getData();
        foreach ($area_report_datas as $area) {
            $data[] = [
                $area->zone_name ?? 'N/A',
                $area->area_name ?? 'N/A',
                $area->total_orders ?? 0,
                round($area->avg_daily_orders),
                $area->client_count,
                $area->branch_count,
                $area->courier_count,
                secondsToTime($area->avg_arrival_time),
                secondsToTime($area->avg_pickup_time),
                secondsToTime($area->avg_reached_time),
                secondsToTime($area->avg_pickup_to_delivered),
                secondsToTime($area->avg_process_time),
                round($area->avg_distance, 2) . ' KM',
            ];
        }

        return $data;
    }

    public function headers(): array
    {
        return [
            'Zone Name',
            'Area Name',
            'Total Orders',
            'Average Daily Orders',
            'Client Count',
            'Branch Count',
            'Courier Count',
            'Average Arrival Time',
            'Average Pickup Time',
            'Average Reached Time',
            'Average Pickup to Delivered Time',
            "Average Process Time (In Minutes)",
            'Average Distance B/W',
        ];
    }

    public function getData()
    {
        $request = $this->export->filters;


        $zone = isset($request['zone']) ? $request['zone'] : null;

        $fromDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'])->startOfDay()
            : now()->subDays(6)->startOfDay();

        $toDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'])->endOfDay()
            : now()->endOfDay();

        $fromDate = Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDate = Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);


        $totalDays = $fromDate->diffInDays($toDate) + 1;

        $reports = OrderReport::query()
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones', 'zones.id', 'client_shops.zone_id')
            ->leftJoin('regions', 'regions.id', 'zones.region_id')
            ->leftJoin('captains', 'captains.id', 'order_reports.captain_id')
            ->leftJoin('clients', 'clients.id', 'order_reports.client_id')
            ->whereBetween('order_reports.final_status_at', [$fromDate, $toDate])
            ->finishedOrders()
            ->belongsToMe()
            ->when($zone, function ($query, $zone) {
                $query->where('zones.id', $zone);
            })
            ->groupBy('zones.id')
            ->select([
                'zones.id as zone_id',
                'zones.name as zone_name',
                'regions.name as area_name',
                DB::raw('COUNT(DISTINCT order_reports.id) as total_orders'),
                DB::raw("ROUND(COUNT(DISTINCT order_reports.id) / $totalDays, 2) as avg_daily_orders"),
                DB::raw('COUNT(DISTINCT order_reports.client_id) as client_count'),
                DB::raw('COUNT(DISTINCT order_reports.shop_id) as branch_count'),
                DB::raw('COUNT(DISTINCT order_reports.captain_id) as courier_count'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, order_reports.start_ride_at, order_reports.reached_shop_at)) as avg_arrival_time'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, order_reports.reached_shop_at, order_reports.order_picked_at)) as avg_pickup_time'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, order_reports.shipped_at, order_reports.reached_dest_at)) as avg_reached_time'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, order_reports.order_picked_at, order_reports.reached_dest_at)) as avg_pickup_to_delivered'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, order_reports.order_created_at, order_reports.final_status_at)) as avg_process_time'),
                DB::raw('AVG(shop_to_delivery_km) as avg_distance')
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

        $fromDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'])->startOfDay()
            : now()->subDays(6)->startOfDay();

        $toDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'])->endOfDay()
            : now()->endOfDay();

        $fromDate = Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDate = Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);
        
        $totalDays = $fromDate->diffInDays($toDate) + 1;

        return OrderReport::query()
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones', 'zones.id', 'client_shops.zone_id')
            ->leftJoin('regions', 'regions.id', 'zones.region_id')
            ->leftJoin('captains', 'captains.id', 'order_reports.captain_id')
            ->leftJoin('clients', 'clients.id', 'order_reports.client_id')
            ->whereBetween('order_reports.final_status_at', [$fromDate, $toDate])
            ->finishedOrders()
            ->belongsToMe()
            ->when($zone, function ($query, $zone) {
                $query->where('zones.id', $zone);
            })
            ->groupBy('zones.id')
            ->select([
                'zones.id as zone_id',
                'zones.name as zone_name',
                'regions.name as area_name',
                DB::raw('COUNT(DISTINCT order_reports.id) as total_orders'),
                DB::raw("ROUND(COUNT(DISTINCT order_reports.id) / $totalDays, 2) as avg_daily_orders"),
                DB::raw('COUNT(DISTINCT order_reports.client_id) as client_count'),
                DB::raw('COUNT(DISTINCT order_reports.shop_id) as branch_count'),
                DB::raw('COUNT(DISTINCT order_reports.captain_id) as courier_count'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, order_reports.start_ride_at, order_reports.reached_shop_at)) as avg_arrival_time'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, order_reports.reached_shop_at, order_reports.order_picked_at)) as avg_pickup_time'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, order_reports.shipped_at, order_reports.reached_dest_at)) as avg_reached_time'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, order_reports.order_picked_at, order_reports.reached_dest_at)) as avg_pickup_to_delivered'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, order_reports.order_created_at, order_reports.final_status_at)) as avg_process_time'),
                DB::raw('AVG(shop_to_delivery_km) as avg_distance')
            ])
            ->count();



    }

}
