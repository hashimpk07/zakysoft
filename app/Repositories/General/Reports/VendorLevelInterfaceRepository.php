<?php

namespace App\Repositories\General\Reports;
use App\Interfaces\General\Reports\VendorLevelInterface;
use App\ThirdPartyLogisticCompany;
use App\OrderStatus;
use Illuminate\Http\Request;


use Illuminate\Support\Facades\DB;

class VendorLevelInterfaceRepository implements VendorLevelInterface
{
    public function getVendorReports(Request $request, int $perPage)
    {
        $company = $request->get('company');
        $fromDate = $request->fromDate;
        $toDate = $request->toDate;

        return ThirdPartyLogisticCompany::query()
                ->select([
                    // identifiers (match resource keys)
                    'third_party_logistic_companies.id as vendor_code',
                    'third_party_logistic_companies.name as vendor_name',

                    // zone / area
                    DB::raw("COUNT(DISTINCT shop_zone.id) as zone_count"),
                    DB::raw("COUNT(DISTINCT shop_region.id) as area_count"),

                    // couriers
                    DB::raw("COUNT(DISTINCT captains.id) as courier_count"),
                    DB::raw("COUNT(DISTINCT CASE WHEN captains.captain_employment_type_id = 1 THEN captains.id END) as sponsored_couriers"),
                    DB::raw("COUNT(DISTINCT CASE WHEN captains.captain_employment_type_id IN (2,3,4) THEN captains.id END) as freelance_couriers"),

                    // orders
                    DB::raw("COUNT(DISTINCT order_reports.id) as total_orders"),
                    DB::raw("COUNT(DISTINCT CASE WHEN order_reports.status_id = ".OrderStatus::DELIVERED." THEN order_reports.id END) as delivered_orders"),
                    DB::raw("COUNT(DISTINCT CASE WHEN order_reports.status_id IN (" .
                        implode(',',[
                            OrderStatus::CANCEL,
                            OrderStatus::FORYOU_RETURN_ACCEPTED,
                            OrderStatus::CLIENT_RETURN_ACCEPTED,
                            OrderStatus::CANCEL_REQUEST_ACCEPTED
                        ]) .
                    ") THEN order_reports.id END) as undelivered_orders"),

                    // cancelled_by_clients_orders (sum boolean)
                    DB::raw("SUM(CASE WHEN order_reports.status_id IN (" . implode(',',[
                        OrderStatus::CANCEL,
                        OrderStatus::CLIENT_RETURN_ACCEPTED,
                        OrderStatus::CANCEL_REQUEST_ACCEPTED,
                        OrderStatus::FORYOU_RETURN_ACCEPTED
                    ]) . ") THEN 1 ELSE 0 END) as cancelled_by_clients_orders"),

                    // attendance / success / avg order per captain (keep same semantics as original)
                    DB::raw("COUNT(DISTINCT order_reports.captain_id) as average_attendance"),
                    DB::raw("COALESCE((COUNT(DISTINCT CASE WHEN order_reports.status_id = ".OrderStatus::DELIVERED." THEN order_reports.id END) / NULLIF(COUNT(DISTINCT order_reports.id), 0)) * 100, 0) as average_success_rate"),
                    DB::raw("COALESCE(
                        COUNT(DISTINCT CASE WHEN order_reports.status_id = ".OrderStatus::DELIVERED." THEN order_reports.id END)
                        /
                        NULLIF(COUNT(DISTINCT CASE WHEN order_reports.status_id = ".OrderStatus::DELIVERED." THEN order_reports.captain_id END), 0),
                    0) as average_orders_per_captain"),

                    // time averages in seconds — resource expects seconds keys like avg_start_ride_time etc.
                    DB::raw("AVG(TIMESTAMPDIFF(SECOND, order_accepted_at, start_ride_at)) as avg_start_ride_time"),
                    DB::raw("AVG(TIMESTAMPDIFF(SECOND, start_ride_at, reached_shop_at)) as avg_reached_shop_time"),
                    DB::raw("AVG(TIMESTAMPDIFF(SECOND, reached_shop_at, order_picked_at)) as avg_pickup_time"),
                    DB::raw("AVG(TIMESTAMPDIFF(SECOND, reached_shop_at, shipped_at)) as avg_start_delivery_time"),
                    DB::raw("AVG(TIMESTAMPDIFF(SECOND, shipped_at, reached_dest_at)) as avg_reached_destination_time"),
                    DB::raw("AVG(TIMESTAMPDIFF(SECOND, order_picked_at, final_status_at)) as avg_pickup_to_delivery_time"),
                    DB::raw("AVG(TIMESTAMPDIFF(SECOND, order_accepted_at, final_status_at)) as avg_process_time"),
                ])
                ->leftJoin('captains_third_party_logistic','captains_third_party_logistic.third_party_logistic_company_id','=','third_party_logistic_companies.id')
                ->leftJoin('order_reports','order_reports.captain_id','=','captains_third_party_logistic.captain_id')
                ->leftJoin('captains','captains.id','=','order_reports.captain_id')
                ->leftJoin('client_shops','client_shops.id','=','order_reports.shop_id')
                ->leftJoin('zones as shop_zone','shop_zone.id','=','client_shops.zone_id')
                ->leftJoin('regions as shop_region','shop_region.id','=','shop_zone.region_id')
                ->when($company, function($query, $company) {
                    $query->where('captains_third_party_logistic.third_party_logistic_company_id', $company);
                })
                ->when($fromDate && $toDate, function($q) use ($fromDate, $toDate) {
                    // make sure they're Carbon or valid date strings
                    $q->whereBetween('order_reports.final_status_at', [$fromDate, $toDate]);
                })
                ->groupBy('third_party_logistic_companies.id','third_party_logistic_companies.name')
                ->paginate($perPage)
                ->withQueryString();
    }
}