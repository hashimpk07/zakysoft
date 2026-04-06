<?php

namespace App\Repositories\General\Reports;
use App\Interfaces\General\Reports\HighLevelReportInterface;

use Illuminate\Support\Facades\DB;
use App\Order;
use App\OrderStatus;


class HighLevelReportInterfaceRepository implements HighLevelReportInterface
{
    public function getHighLevelReport($filters, $perPage)
    {
        return Order::query()
            ->select(
                'orders.id',
                'orders.client_order_id',
                'order_reports.order_payment_mode as payment_mode',
                'order_reports.cod_amount',
                'orders.delivery_type as order_type',
                'order_reports.order_payment_mode',
                'orders.delivery_payment_mode',
                'client_users.name as client_name',
                'client_shops.name as shop_name',
                'zones.name as zone_name',
                'order_reports.assigned_by as assigned_by_user_id',
                'assigned_by_users.name as assigned_by_name',
                'regions.name as region_name',
                'quadrants.name as quadrant_name',
                'captain_users.name as captain_name',
                'captains.iqama_number as iqama_no',
                DB::raw("
                  CASE 
                    WHEN third_party_logistic_companies.name IS NOT NULL 
                    THEN CONCAT('ThirdParty - ', third_party_logistic_companies.name) 
                    ELSE captain_employment_types.name 
                  END AS captain_employment_type_name
                "),
                'order_statuses.name as order_status_name',
                'order_statuses.id as order_status_id',
                'orders.created_at',
                'order_reports.order_accepted_at',
                'order_reports.start_ride_at',
                'order_reports.reached_shop_at',
                'order_reports.order_picked_at',
                'order_reports.shipped_at',
                'order_reports.cancellation_reason as cancellation_reason',
                'order_reports.reached_dest_at',
                'order_reports.final_status_at',
                'orders.created_at as acceptance_time',
                'orders.created_at as acceptance_time_taken',
                'orders.created_at as reached_time_taken',
                "orders.created_at as picked_time_taken",
                "orders.created_at as delivered_time_taken",
                'orders.created_at as total_time_taken',
                'orders.shop_to_delivery_km as distance',
                DB::raw("COALESCE(order_reports.last_address, orders.location) as delivery_cordinates"),
                'order_reports.auto_assign_attempts',
                DB::raw("
                      CASE
                          WHEN TIME(order_reports.final_status_at) < '06:00:00'
                          THEN DATE_FORMAT(DATE_SUB(DATE(order_reports.final_status_at), INTERVAL 1 DAY), '%Y-%m-%d')
                          ELSE DATE_FORMAT(DATE(order_reports.final_status_at), '%Y-%m-%d')
                      END AS adjusted_final_status_date
                  "),
                DB::raw("
                  CASE 
                      WHEN captains.id IS NULL THEN 'N/A'
                      WHEN commission_rules.name IS NOT NULL 
                      THEN commission_rules.name 
                      ELSE 'Not Assigned' 
                  END as captain_rule_name
              "),
                'order_reports.relocated_count',
                'order_reports.relocation_history',
                DB::raw("COALESCE(order_reports.first_address, orders.location) AS location")
            )
            ->leftJoin('order_reports', 'orders.id', '=', 'order_reports.order_id')
            ->leftJoin('clients', 'orders.client_id', '=', 'clients.id')
            ->leftJoin('users as client_users', 'clients.user_id', '=', 'client_users.id')
            ->leftJoin('client_shops', 'client_shops.id', '=', 'orders.shopname')
            ->leftJoin('zones', 'client_shops.zone_id', '=', 'zones.id')
            ->leftJoin('regions', 'zones.region_id', '=', 'regions.id')
            ->leftJoin('quadrants', 'regions.quadrant_id', '=', 'quadrants.id')
            ->leftJoin('captains', 'orders.captain_id', '=', 'captains.id')
            ->leftJoin('users as captain_users', 'captains.user_id', '=', 'captain_users.id')
            ->leftJoin('captain_employment_types', 'captains.captain_employment_type_id', '=', 'captain_employment_types.id')
            ->leftJoin('order_statuses', 'orders.status_id', '=', 'order_statuses.id')
            ->leftJoin('users as assigned_by_users', 'order_reports.assigned_by', '=', 'assigned_by_users.id')
            ->leftJoin('captains_third_party_logistic', 'captains.id', '=', 'captains_third_party_logistic.captain_id')
            ->leftJoin('third_party_logistic_companies', 'captains_third_party_logistic.third_party_logistic_company_id', '=', 'third_party_logistic_companies.id')
            ->leftJoin('commission_rules', 'order_reports.captain_rule_id', '=', 'commission_rules.id')
            ->when($filters['client_order_id'] ?? null, function ($query, $client_order_id) {
                $query->where('orders.client_order_id', 'like', '%' . $client_order_id . '%');
            })
            ->when($filters['client'] ?? null, function ($query, $client) {
                $query->where('orders.client_id', $client);
            })
            ->when($filters['captain'] ?? null, function ($query, $captain) {
                return $query->whereHas('captain', function ($query) use ($captain) {
                    $query->whereIn('captains.user_id', is_array($captain) ? $captain : [$captain]);
                });
            })
            ->when($filters['companies'] ?? null, function ($query, $thirdPartyCompany) {
                $query->whereIn('third_party_logistic_companies.id', is_array($thirdPartyCompany) ? $thirdPartyCompany : [$thirdPartyCompany]);
            })
            ->when($filters['assigned_by'] ?? null, function ($query, $assignedBy) {
                $query->where("order_reports.assigned_by", $assignedBy);
            })
            ->when($filters['status_id'] ?? null, function ($query, $status_id) {
                $query->where("orders.status_id", $status_id);
            })
            ->with(['pendingReasonLog.reason', 'lastLog.createdBy', 'orderReport.assignedBy'])
            ->whereBetween('orders.delivery_date', [
                $filters['fromDate'],
                $filters['toDate']
            ])
            ->whereIn("orders.status_id", [
                OrderStatus::DELIVERED,
                OrderStatus::CANCEL,
                OrderStatus::RETURN_TO_CLIENT,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
                OrderStatus::CANCEL_REQUEST_ACCEPTED,
                OrderStatus::FORYOU_RETURN_ACCEPTED
            ])
            ->orderBy('orders.delivery_date', 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }
}