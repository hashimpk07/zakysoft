<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\OrderStatus;
use App\ThirdPartyLogisticCompany;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class VenderLevelReportExport extends QueueExport
{
    protected int $chunk = 2000;

    protected string $file_name = 'vender-level-report';

    public $timeout = 100000;

    public function data(): array
    {
        $data = [];
        $vendor_report_datas = $this->getData();
        foreach ($vendor_report_datas as $vendor) {
            $data[] = [
                $vendor->company_id ?? 'N/A',
                $vendor->company_name ?? 'N/A',
                $vendor->zone_count ?? 0,
                $vendor->area_count,
                $vendor->courier_count,
                $vendor->sponsored_count,
                $vendor->freelance_couriers,
                $vendor->total_orders,
                $vendor->delivered_orders,
                $vendor->undelivered_orders,
                $vendor->cancelled_by_clients_orders,
                $vendor->average_attendance,
                round($vendor->average_order_success_rate, 2),
                round($vendor->average_orders_per_day, 2),
                secondsToTime($vendor->average_start_ride_time ?? 0),
                secondsToTime($vendor->average_reached_shop_at_time ?? 0),
                secondsToTime($vendor->average_pickup_time ?? 0),
                secondsToTime($vendor->average_start_delivery_time ?? 0),
                secondsToTime($vendor->average_reached_destination_time ?? 0),
                secondsToTime($vendor->average_pickup_to_delivery_time ?? 0),
                secondsToTime($vendor->average_process_time ?? 0),
            ];
        }

        return $data;
    }

    public function headers(): array
    {
        return [
            'Vendor Code',
            'Vendor Name',
            'Zone Count',
            'Area Count',
            'Courier Count',
            'Sponsored Couriers',
            'Freelance Couriers',
            'Total Orders',
            'Delivered Orders',
            'Undelivered Orders',
            'Cancelled By Clients Orders',
            'Average Attendance',
            "Average Success Rate",
            'Average Order / Captain',
            'Average Start Ride Time',
            'Average Reached Shop Time',
            'Average Pickup Time',
            'Average Start Delivery Time',
            'Average Reached Destination Time',
            'Average Pickup To Delivery Time',
            'Average Process Time'
        ];
    }

    public function getData()
    {
        $request = $this->export->filters;

        $company = isset($request['company']) ? $request['company'] : null;

        $fromDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'])->startOfDay()
            : now()->subDays(6)->startOfDay();

        $toDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'])->endOfDay()
            : now()->endOfDay();


        $fromDate = Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDate = Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);


        $totalDays = $fromDate->diffInDays($toDate) + 1;

        $vendors = ThirdPartyLogisticCompany::query()
            ->select([
                "third_party_logistic_companies.id as company_id",
                "third_party_logistic_companies.name as company_name",
                DB::raw("COUNT(DISTINCT order_reports.id) as total_orders"),
                DB::raw("$totalDays as total_days"),
                DB::raw("
                COALESCE(
                    COUNT(DISTINCT CASE WHEN order_reports.status_id = " . OrderStatus::DELIVERED . " THEN order_reports.id END) 
                    / 
                    NULLIF(COUNT(DISTINCT CASE WHEN order_reports.status_id = " . OrderStatus::DELIVERED . " THEN order_reports.captain_id END), 0),
                0) as average_orders_per_day
                "),
                DB::raw("COUNT(DISTINCT CASE WHEN order_reports.status_id = " . OrderStatus::DELIVERED . " THEN order_reports.id END) as delivered_orders"),
                DB::raw("COALESCE((COUNT(DISTINCT CASE WHEN order_reports.status_id = " . OrderStatus::DELIVERED . " THEN order_reports.id END) / NULLIF(COUNT(DISTINCT order_reports.id), 0)) * 100, 0) as average_order_success_rate"),
                DB::raw("COUNT(DISTINCT CASE WHEN order_reports.status_id IN (" .
                    implode(',', [
                        OrderStatus::CANCEL,
                        OrderStatus::FORYOU_RETURN_ACCEPTED,
                        OrderStatus::CLIENT_RETURN_ACCEPTED,
                        OrderStatus::CANCEL_REQUEST_ACCEPTED
                    ]) .
                    ") THEN order_reports.id END) as undelivered_orders"),
                DB::raw("COUNT(DISTINCT order_reports.captain_id) as average_attendance"),
                DB::raw("COUNT(DISTINCT shop_zone.id) as zone_count"),
                DB::raw("COUNT(DISTINCT shop_region.id) as area_count"),
                DB::raw("AVG(TIMESTAMPDIFF(SECOND, order_accepted_at, start_ride_at)) as average_start_ride_time"),
                DB::raw("AVG(TIMESTAMPDIFF(SECOND, start_ride_at, reached_shop_at)) as average_reached_shop_at_time"),
                DB::raw("AVG(TIMESTAMPDIFF(SECOND, reached_shop_at, order_picked_at)) as average_pickup_time"),
                DB::raw("AVG(TIMESTAMPDIFF(SECOND, reached_shop_at, shipped_at)) as average_start_delivery_time"),
                DB::raw("AVG(TIMESTAMPDIFF(SECOND, shipped_at, reached_dest_at)) as average_reached_destination_time"),
                DB::raw("AVG(TIMESTAMPDIFF(SECOND, order_picked_at, final_status_at)) as average_pickup_to_delivery_time"),
                DB::raw("AVG(TIMESTAMPDIFF(SECOND, order_accepted_at, final_status_at)) as average_process_time"),
                DB::raw("SUM(CASE WHEN order_reports.status_id IN (" . implode(',', [
                    OrderStatus::CANCEL,
                    OrderStatus::CLIENT_RETURN_ACCEPTED,
                    OrderStatus::CANCEL_REQUEST_ACCEPTED,
                    OrderStatus::FORYOU_RETURN_ACCEPTED
                ]) . ") THEN 1 ELSE 0 END) as cancelled_by_clients_orders"),
                DB::raw("COUNT(DISTINCT CASE WHEN captains.captain_employment_type_id IS NOT NULL THEN captains.id END) as courier_count"),
                DB::raw("COUNT(DISTINCT CASE WHEN captains.captain_employment_type_id = 1 THEN captains.id END) as sponsored_count"),
                DB::raw("COUNT(DISTINCT CASE WHEN captains.captain_employment_type_id IN (2,3,4) THEN captains.id END) as freelance_couriers"),
            ])
            ->leftJoin('captains_third_party_logistic', 'captains_third_party_logistic.third_party_logistic_company_id', '=', 'third_party_logistic_companies.id')
            ->leftJoin('order_reports', 'order_reports.captain_id', '=', 'captains_third_party_logistic.captain_id')
            ->leftJoin('captains', 'captains.id', '=', 'order_reports.captain_id')
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->when($company, function ($query, $company) {
                $query->where('captains_third_party_logistic.third_party_logistic_company_id', '=', $company);
            })
            ->whereBetween('order_reports.final_status_at', [$fromDate, $toDate])
            ->groupBy('third_party_logistic_companies.id', 'third_party_logistic_companies.name')
            ->limit($this->chunk)
            ->offset($this->chunk * $this->export->page_done ?? 0)
            ->get();

        return $vendors;
    }

    public function count(): int
    {
        $request = $this->export->filters;

        $company = isset($request['company']) ? $request['company'] : null;

        $fromDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'])->startOfDay()
            : now()->startOfMonth()->startOfDay();

        $toDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'])->endOfDay()
            : now()->endOfDay();

        $fromDate = Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDate = Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        $totalDays = $fromDate->diffInDays($toDate) + 1;

        return ThirdPartyLogisticCompany::query()
            ->select([
                "third_party_logistic_companies.id as company_id",
                "third_party_logistic_companies.name as company_name",
                DB::raw("COUNT(DISTINCT order_reports.id) as total_orders"),
                DB::raw("$totalDays as total_days"),
                DB::raw("COALESCE(COUNT(DISTINCT order_reports.id) / NULLIF(COUNT(DISTINCT order_reports.captain_id), 0), 0) as average_orders_per_day"),
                DB::raw("COUNT(DISTINCT CASE WHEN order_reports.status_id = " . OrderStatus::DELIVERED . " THEN order_reports.id END) as delivered_orders"),
                DB::raw("COALESCE((COUNT(DISTINCT CASE WHEN order_reports.status_id = " . OrderStatus::DELIVERED . " THEN order_reports.id END) / NULLIF(COUNT(DISTINCT order_reports.id), 0)) * 100, 0) as average_order_success_rate"),
                DB::raw("COUNT(DISTINCT CASE WHEN order_reports.status_id IN (" .
                    implode(',', [
                        OrderStatus::CANCEL,
                        OrderStatus::FORYOU_RETURN_ACCEPTED,
                        OrderStatus::CLIENT_RETURN_ACCEPTED,
                        OrderStatus::CANCEL_REQUEST_ACCEPTED
                    ]) .
                    ") THEN order_reports.id END) as undelivered_orders"),
                DB::raw("COUNT(DISTINCT order_reports.captain_id) as average_attendance"),
                DB::raw("COUNT(DISTINCT shop_zone.id) as zone_count"),
                DB::raw("COUNT(DISTINCT shop_region.id) as area_count"),
                DB::raw("AVG(TIMESTAMPDIFF(SECOND, order_accepted_at, start_ride_at)) as average_start_ride_time"),
                DB::raw("AVG(TIMESTAMPDIFF(SECOND, start_ride_at, reached_shop_at)) as average_reached_shop_at_time"),
                DB::raw("AVG(TIMESTAMPDIFF(SECOND, reached_shop_at, order_picked_at)) as average_pickup_time"),
                DB::raw("AVG(TIMESTAMPDIFF(SECOND, reached_shop_at, shipped_at)) as average_start_delivery_time"),
                DB::raw("AVG(TIMESTAMPDIFF(SECOND, shipped_at, reached_dest_at)) as average_reached_destination_time"),
                DB::raw("AVG(TIMESTAMPDIFF(SECOND, order_picked_at, final_status_at)) as average_pickup_to_delivery_time"),
                DB::raw("AVG(TIMESTAMPDIFF(SECOND, order_accepted_at, final_status_at)) as average_process_time"),
                DB::raw("SUM(CASE WHEN order_reports.status_id IN (" . implode(',', [
                    OrderStatus::CANCEL,
                    OrderStatus::CLIENT_RETURN_ACCEPTED,
                    OrderStatus::CANCEL_REQUEST_ACCEPTED,
                    OrderStatus::FORYOU_RETURN_ACCEPTED
                ]) . ") THEN 1 ELSE 0 END) as cancelled_by_clients_orders"),
                DB::raw("COUNT(DISTINCT CASE WHEN captains.captain_employment_type_id IS NOT NULL THEN captains.id END) as courier_count"),
                DB::raw("COUNT(DISTINCT CASE WHEN captains.captain_employment_type_id = 1 THEN captains.id END) as sponsored_count"),
                DB::raw("COUNT(DISTINCT CASE WHEN captains.captain_employment_type_id IN (2,3,4) THEN captains.id END) as freelance_couriers"),
            ])
            ->leftJoin('captains_third_party_logistic', 'captains_third_party_logistic.third_party_logistic_company_id', '=', 'third_party_logistic_companies.id')
            ->leftJoin('order_reports', 'order_reports.captain_id', '=', 'captains_third_party_logistic.captain_id')
            ->leftJoin('captains', 'captains.id', '=', 'order_reports.captain_id')
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->when($company, function ($query, $company) {
                $query->where('captains_third_party_logistic.third_party_logistic_company_id', '=', $company);
            })
            ->whereBetween('order_reports.final_status_at', [$fromDate, $toDate])
            ->groupBy('third_party_logistic_companies.id', 'third_party_logistic_companies.name')
            ->count();
    }

}
