<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\Order;
use App\OrderStatus;
use App\User;
use App\Services\ReportFieldsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClientHighLevelReportExport extends QueueExport
{
    protected int $chunk = 2000;
    protected string $file_name = 'high-level-client-report';

    public $timeout = 100000;

    public function data(): array
    {
        $data = [];
        $high_level_report_datas = $this->getData();
        $selectedFields = $this->export->filters['selected_fields'] ?? [];
        foreach ($high_level_report_datas as $high_level) {
            $row = [];
            foreach ($selectedFields as $field) {
                $row[] = $this->getFieldValue($field, $high_level);
            }
            $data[] = $row;
        }

        return $data;
    }

    protected function getFieldValue($field, $record): string
    {
        switch ($field) {
            case 'order_id':
                return 'OR#' . $record->id;
            case 'client_order_id':
                return $record->client_order_id;
            case 'order_type':
                return $record->order_type;
            case 'cancellation_reason':
                return $record->order_status_id === OrderStatus::CLIENT_RETURN_ACCEPTED
                    ? ($record->pendingReasonLog->reason->reason
                        ?? $record->cancellation_reason
                        ?? $record->lastLog->note
                        ?? 'N/A')
                    : ($record->lastLog->note ?? 'N/A');
            case 'cancelled_by':
                return $record->order_status_id === OrderStatus::DELIVERED
                    ? 'N/A'
                    : ($record->lastLog && $record->lastLog->canceled_by
                        ? ($record->lastLog->createdBy->name ?? 'Unknown')
                        : 'By System');
            case 'assigned_by':
                return $record->assigned_by ?? '';
            case 'created_at':
                return Carbon::parse($record->created_at)->format('d-m-Y');
            case 'created_at_time':
                return Carbon::parse($record->created_at)->format('h:i:s A');
            case 'order_accepted_at':
                return $record->order_accepted_at ? Carbon::parse($record->order_accepted_at)->format('d-m-Y') : 'N/A';
            case 'order_accepted_at_time':
                return $record->order_accepted_at ? Carbon::parse($record->order_accepted_at)->format('h:i:s A') : 'N/A';
            case 'start_ride_at':
                return $record->start_ride_at ? Carbon::parse($record->start_ride_at)->format('d-m-Y') : 'N/A';
            case 'start_ride_at_time':
                return $record->start_ride_at ? Carbon::parse($record->start_ride_at)->format('h:i:s A') : 'N/A';
            case 'reached_shop_at':
                return $record->reached_shop_at ? Carbon::parse($record->reached_shop_at)->format('d-m-Y') : 'N/A';
            case 'reached_shop_at_time':
                return $record->reached_shop_at ? Carbon::parse($record->reached_shop_at)->format('h:i:s A') : 'N/A';
            case 'order_picked_at':
                return $record->order_picked_at ? Carbon::parse($record->order_picked_at)->format('d-m-Y') : 'N/A';
            case 'order_picked_at_time':
                return $record->order_picked_at ? Carbon::parse($record->order_picked_at)->format('h:i:s A') : 'N/A';
            case 'shipped_at':
                return $record->shipped_at ? Carbon::parse($record->shipped_at)->format('d-m-Y') : 'N/A';
            case 'shipped_at_time':
                return $record->shipped_at ? Carbon::parse($record->shipped_at)->format('h:i:s A') : 'N/A';
            case 'reached_dest_at':
                return $record->reached_dest_at ? Carbon::parse($record->reached_dest_at)->format('d-m-Y') : 'N/A';
            case 'reached_dest_at_time':
                return $record->reached_dest_at ? Carbon::parse($record->reached_dest_at)->format('h:i:s A') : 'N/A';
            case 'business_day':
                return $record->adjusted_final_status_date ? Carbon::parse($record->adjusted_final_status_date)->format('d-m-Y') : 'N/A';
            case 'final_status_at':
                return $record->final_status_at ? Carbon::parse($record->final_status_at)->format('d-m-Y') : 'N/A';
            case 'final_status_at_time':
                return $record->final_status_at ? Carbon::parse($record->final_status_at)->format('h:i:s A') : 'N/A';
            case 'acceptance_time':
                return $record->order_accepted_at ? secondsToTime(Carbon::parse($record->order_accepted_at)->diffInSeconds(Carbon::parse($record->created_at))) : 'N/A';
            case 'reached_time_taken':
                return $record->order_accepted_at ? secondsToTime(Carbon::parse($record->order_accepted_at)->diffInSeconds(Carbon::parse($record->reached_shop_at))) : 'N/A';
            case 'picked_time_taken':
                return $record->order_picked_at ? secondsToTime(Carbon::parse($record->order_picked_at)->diffInSeconds(Carbon::parse($record->reached_shop_at))) : 'N/A';
            case 'delivered_time_taken':
                return $record->order_picked_at ? secondsToTime(Carbon::parse($record->order_picked_at)->diffInSeconds(Carbon::parse($record->final_status_at))) : 'N/A';
            case 'total_time_taken':
                return $record->final_status_at ? secondsToTime(Carbon::parse($record->final_status_at)->diffInSeconds(Carbon::parse($record->created_at))) : 'N/A';
            case 'distance':
                return (string) $record->distance;
            case 'arrival_time':
                return $record->reached_shop_at ? secondsToTime(Carbon::parse($record->reached_shop_at)->diffInSeconds(Carbon::parse($record->created_at))) : 'N/A';
            default:
                return (string) ($record->$field ?? '');
        }
    }

    public function headers(): array
    {
        $selectedFields = $this->export->filters['selected_fields'] ?? [];
        $allFields = ReportFieldsService::getFields('high_level_client_report');

        // Create an associative array mapping keys to labels
        $fieldLabels = collect($allFields)->pluck('label', 'key')->toArray();

        // Map selected field keys to their labels
        return array_map(function ($fieldKey) use ($fieldLabels) {
            return $fieldLabels[$fieldKey] ?? $fieldKey;
        }, $selectedFields);
    }

    public function getData()
    {
        $request = $this->export->filters;

        $client_order_id = $request['client_order_id'] ?? null;
        $client = $request['client'] ?? null;
        $thirdPartyCompany = $request['companies'] ?? null;
        $assignedBy = $request['assigned_by'] ?? null;
        $status_id = $request['status_id'] ?? null;
        $captain = $request['captain'] ?? null;

        $fromDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'] . ' ' . ($request['order_time_from'] ?? '06:00:00'))
            : now()->subDays(6)->startOfDay()->setTimeFromTimeString('06:00:00');

        $toDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'] . ' ' . ($request['order_time_to'] ?? '05:59:59'))->addDay()
            : now()->copy()->endOfDay()->addDay()->setTimeFromTimeString('05:59:59');


        $reports = Order::
            select(
                'orders.id',
                'orders.client_order_id',
                'orders.delivery_type as order_type',
                'orders.delivery_payment_mode as payment_mode',
                DB::raw("(CASE WHEN order_payments.payment_mode THEN order_payments.payment_mode ELSE orders.delivery_payment_mode END) as order_payment_mode"),
                'order_payments.cash as cod_amount',
                'orders.delivery_payment_mode',
                'client_users.name as client_name',
                'client_shops.name as shop_name',
                'zones.name as zone_name',
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
                   DB::raw("(
              SELECT 
                  CASE 
                      WHEN captains.id IS NULL
                          THEN users.name
                      ELSE 'By SYSTEM'
                  END
              FROM order_logs
              LEFT JOIN users ON users.id = order_logs.created_by
              LEFT JOIN captains ON captains.user_id = users.id
              WHERE order_logs.order_id = orders.id
                AND order_logs.status_id IN (
                    " . OrderStatus::ACCEPT . ",
                    " . OrderStatus::ASSIGNED_BY . "
                )
              ORDER BY order_logs.id DESC
              LIMIT 1
          ) AS assigned_by"),
                'order_statuses.name as order_status_name',
                'order_statuses.id as order_status_id',
                'orders.created_at',
                'order_reports.order_accepted_at',
                'order_reports.start_ride_at',
                'order_reports.reached_shop_at',
                'order_reports.order_picked_at',
                'order_reports.shipped_at',
                'order_reports.reached_dest_at',
                'order_reports.final_status_at',
                'orders.created_at as acceptance_time',
                'orders.created_at as acceptance_time_taken',
                'orders.created_at as reached_time_taken',
                "orders.created_at as picked_time_taken",
                "orders.created_at as delivered_time_taken",
                'orders.created_at as total_time_taken',
                'orders.shop_to_delivery_km as distance',
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
                        WHEN captains.id IS NULL THEN ''
                        WHEN commission_rules.name IS NOT NULL 
                        THEN commission_rules.name 
                        ELSE 'Not Assigned' 
                    END as captain_rule_name
                "),
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
            ->leftJoin('users as assigned_by_users', 'orders.created_by', '=', 'assigned_by_users.id')
            ->leftJoin('captains_third_party_logistic', 'captains.id', '=', 'captains_third_party_logistic.captain_id')
            ->leftJoin('third_party_logistic_companies', 'captains_third_party_logistic.third_party_logistic_company_id', '=', 'third_party_logistic_companies.id')
            ->leftJoin('commission_rules', 'order_reports.captain_rule_id', '=', 'commission_rules.id')->leftJoinSub(DB::table('order_payments')->selectRaw('MAX(id) max_id, order_id')->groupBy('order_id'), 'last_payment_id', function ($join) {
                $join->on('orders.id', '=', 'last_payment_id.order_id');
            })
            ->belongsToUser(User::find($this->export->created_by))
            ->withLastLog('lastLog.createdBy')
            ->leftJoin('order_payments', 'order_payments.id', 'last_payment_id.max_id')
            ->whereBetween('orders.delivery_date', [$fromDate, $toDate])
            ->whereIn("orders.status_id", [
                OrderStatus::DELIVERED,
                OrderStatus::CANCEL,
                OrderStatus::RETURN_TO_CLIENT,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
                OrderStatus::CANCEL_REQUEST_ACCEPTED,
                OrderStatus::FORYOU_RETURN_ACCEPTED
            ])
            ->when($client_order_id, function ($query) use ($client_order_id) {
                $query->where('orders.client_order_id', 'like', '%' . $client_order_id . '%');
            })
            ->when($client, function ($query) use ($client) {
                $query->where('orders.client_id', $client);
            })
            ->when($captain, function ($query, $captain) {
                return $query->whereHas('captain', function ($query) use ($captain) {
                    $query->whereIn('captains.user_id', $captain);
                });
            })
            ->when($thirdPartyCompany, function ($query) use ($thirdPartyCompany) {
                $query->whereIn('third_party_logistic_companies.id', $thirdPartyCompany);
            })
            ->when($assignedBy, function ($query) use ($assignedBy) {
                $query->whereRaw("
          (
            SELECT 
              CASE 
                WHEN captains.id IS NULL THEN users.id
                ELSE NULL
              END
            FROM order_logs
            LEFT JOIN users ON users.id = order_logs.created_by
            LEFT JOIN captains ON captains.user_id = users.id
            WHERE order_logs.order_id = orders.id
                AND order_logs.status_id IN (
                    " . OrderStatus::ACCEPT . ",
                    " . OrderStatus::ASSIGNED_BY . "
                )
            ORDER BY order_logs.id DESC
            LIMIT 1
          ) = ?
        ", [$assignedBy]);
            })
            ->when($status_id, function ($query) use ($status_id) {
                $query->where("orders.status_id", $status_id);
            })
            ->limit($this->chunk)
            ->offset($this->chunk * $this->export->page_done ?? 0)
            ->get();


        return $reports;
    }

    public function count(): int
    {
        $request = $this->export->filters;


        $client_order_id = $request['client_order_id'] ?? null;
        $client = $request['client'] ?? null;
        $thirdPartyCompany = $request['companies'] ?? null;
        $assignedBy = $request['assigned_by'] ?? null;
        $status_id = $request['status_id'] ?? null;
        $captain = $request['captain'] ?? null;


        $fromDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'] . ' ' . ($request['order_time_from'] ?? '06:00:00'))
            : now()->subDays(6)->startOfDay()->setTimeFromTimeString('06:00:00');

        $toDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'] . ' ' . ($request['order_time_to'] ?? '05:59:59'))->addDay()
            : now()->copy()->endOfDay()->addDay()->setTimeFromTimeString('05:59:59');

        return Order::
            select(
                'orders.id',
                'orders.client_order_id',
                'orders.delivery_payment_mode as payment_mode',
                DB::raw("(CASE WHEN order_payments.payment_mode THEN order_payments.payment_mode ELSE orders.delivery_payment_mode END) as order_payment_mode"),
                'order_payments.cash as cod_amount',
                'orders.delivery_payment_mode',
                'client_users.name as client_name',
                'client_shops.name as shop_name',
                'zones.name as zone_name',
                'regions.name as region_name',
                'quadrants.name as quadrant_name',
                'captain_users.name as captain_name',
                   DB::raw("(
              SELECT 
                  CASE 
                      WHEN captains.id IS NULL
                          THEN users.name
                      ELSE 'By SYSTEM'
                  END
              FROM order_logs
              LEFT JOIN users ON users.id = order_logs.created_by
              LEFT JOIN captains ON captains.user_id = users.id
              WHERE order_logs.order_id = orders.id
                AND order_logs.status_id IN (
                    " . OrderStatus::ACCEPT . ",
                    " . OrderStatus::ASSIGNED_BY . "
                )
              ORDER BY order_logs.id DESC
              LIMIT 1
          ) AS assigned_by"),
                'order_statuses.name as order_status_name',
                'order_statuses.name as order_status_by_status',
                'orders.created_at',
                'order_reports.order_accepted_at',
                'order_reports.start_ride_at',
                'order_reports.reached_shop_at',
                'order_reports.order_picked_at',
                'order_reports.shipped_at',
                'order_reports.reached_dest_at',
                'order_reports.final_status_at',
                'orders.created_at as acceptance_time',
                'orders.created_at as acceptance_time_taken',
                'orders.created_at as reached_time_taken',
                "orders.created_at as picked_time_taken",
                "orders.created_at as delivered_time_taken",
                'orders.created_at as total_time_taken',
                'orders.shop_to_delivery_km as distance',
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
            ->leftJoin('users as assigned_by_users', 'orders.created_by', '=', 'assigned_by_users.id')
            ->leftJoin('captains_third_party_logistic', 'captains.id', '=', 'captains_third_party_logistic.captain_id')
            ->leftJoin('third_party_logistic_companies', 'captains_third_party_logistic.third_party_logistic_company_id', '=', 'third_party_logistic_companies.id')
            ->leftJoinSub(DB::table('order_payments')->selectRaw('MAX(id) max_id, order_id')->groupBy('order_id'), 'last_payment_id', function ($join) {
                $join->on('orders.id', '=', 'last_payment_id.order_id');
            })
            ->belongsToUser(User::find($this->export->created_by))
            ->withLastLog('lastLog.createdBy')
            ->leftJoin('order_payments', 'order_payments.id', 'last_payment_id.max_id')
            ->whereBetween('orders.delivery_date', [$fromDate, $toDate])
            ->whereIn("orders.status_id", [
                OrderStatus::DELIVERED,
                OrderStatus::CANCEL,
                OrderStatus::RETURN_TO_CLIENT,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
                OrderStatus::CANCEL_REQUEST_ACCEPTED,
                OrderStatus::FORYOU_RETURN_ACCEPTED
            ])
            ->when($client_order_id, function ($query) use ($client_order_id) {
                $query->where('orders.client_order_id', 'like', '%' . $client_order_id . '%');
            })
            ->when($client, function ($query) use ($client) {
                $query->where('orders.client_id', $client);
            })
            ->when($captain, function ($query, $captain) {
                return $query->whereHas('captain', function ($query) use ($captain) {
                    $query->whereIn('captains.user_id', $captain);
                });
            })
            ->when($thirdPartyCompany, function ($query) use ($thirdPartyCompany) {
                $query->whereIn('third_party_logistic_companies.id', $thirdPartyCompany);
            })
            ->when($assignedBy, function ($query) use ($assignedBy) {
                $query->whereRaw("
          (
            SELECT 
              CASE 
                WHEN captains.id IS NULL THEN users.id
                ELSE NULL
              END
            FROM order_logs
            LEFT JOIN users ON users.id = order_logs.created_by
            LEFT JOIN captains ON captains.user_id = users.id
            WHERE order_logs.order_id = orders.id
                AND order_logs.status_id IN (
                    " . OrderStatus::ACCEPT . ",
                    " . OrderStatus::ASSIGNED_BY . "
                )
            ORDER BY order_logs.id DESC
            LIMIT 1
          ) = ?
        ", [$assignedBy]);
            })
            ->when($status_id, function ($query) use ($status_id) {
                $query->where("orders.status_id", $status_id);
            })
            ->count();

    }

}
