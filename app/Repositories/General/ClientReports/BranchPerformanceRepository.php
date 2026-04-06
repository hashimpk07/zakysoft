<?php

namespace App\Repositories\General\ClientReports;

use App\Interfaces\General\ClientReports\BranchPerformanceInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use App\OrderReport;
use App\OrderStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BranchPerformanceRepository implements BranchPerformanceInterface
{
   public function baseQuery(array $filters): Builder
    {
        return OrderReport::query()

            ->leftJoin('clients', 'order_reports.client_id', '=', 'clients.id')
            ->leftJoin('users as client_user', 'clients.user_id', '=', 'client_user.id')

            ->whereBetween('order_reports.final_status_at', [
                $filters['startDateTime'],
                $filters['endDateTime']
            ])

            ->when($filters['client'] ?? null, function ($query, $client) {
                $query->where('order_reports.client_id', $client);
            })

            ->when($filters['shop'] ?? null, function ($query, $shop) {
                $query->where('order_reports.shop_id', $shop);
            });
    }


    public function getTotals($query)
    {
        return (clone $query)
            ->select(
                DB::raw('COUNT(order_reports.id) as total_orders'),

                DB::raw('SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN 1 ELSE 0 END
                ) as delivered_orders'),

                DB::raw('SUM(
                    CASE WHEN order_reports.status_id != '.OrderStatus::DELIVERED.'
                    THEN 1 ELSE 0 END
                ) as failed_orders'),

                DB::raw('SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN TIMESTAMPDIFF(MINUTE, order_reports.order_accepted_at, order_reports.reached_shop_at)
                    ELSE 0 END
                ) / NULLIF(SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN 1 ELSE 0 END
                ),0) as avg_arrival_time'),

                DB::raw('SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN TIMESTAMPDIFF(MINUTE, order_reports.reached_shop_at, order_reports.order_picked_at)
                    ELSE 0 END
                ) / NULLIF(SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN 1 ELSE 0 END
                ),0) as avg_waiting_time'),

                DB::raw('SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN TIMESTAMPDIFF(MINUTE, order_reports.order_picked_at, order_reports.final_status_at)
                    ELSE 0 END
                ) / NULLIF(SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN 1 ELSE 0 END
                ),0) as avg_pickup_to_delivery'),

                DB::raw('SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN TIMESTAMPDIFF(MINUTE, order_reports.order_accepted_at, order_reports.reached_shop_at)
                    ELSE 0 END +
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN TIMESTAMPDIFF(MINUTE, order_reports.reached_shop_at, order_reports.order_picked_at)
                    ELSE 0 END +
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN TIMESTAMPDIFF(MINUTE, order_reports.order_picked_at, order_reports.final_status_at)
                    ELSE 0 END
                ) / NULLIF(SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN 1 ELSE 0 END
                ),0) as avg_total_cycle'),

                DB::raw('SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN order_reports.shop_to_delivery_km
                    ELSE 0 END
                ) / NULLIF(SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN 1 ELSE 0 END
                ),0) as avg_distance')
            )
            ->first();
    }


    public function getReports($query, $perPage)
    {
        return (clone $query)
            ->select(
                'order_reports.shop_id',
                'order_reports.client_id',

                DB::raw('COUNT(order_reports.id) as total_orders'),

                DB::raw('SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN 1 ELSE 0 END
                ) as delivered_orders'),

                DB::raw('SUM(
                    CASE WHEN order_reports.status_id != '.OrderStatus::DELIVERED.'
                    THEN 1 ELSE 0 END
                ) as failed_orders'),

                DB::raw('SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN TIMESTAMPDIFF(MINUTE, order_reports.order_accepted_at, order_reports.reached_shop_at)
                    ELSE 0 END
                ) / NULLIF(SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN 1 ELSE 0 END
                ),0) as avg_arrival_time'),

                DB::raw('SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN TIMESTAMPDIFF(MINUTE, order_reports.reached_shop_at, order_reports.order_picked_at)
                    ELSE 0 END
                ) / NULLIF(SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN 1 ELSE 0 END
                ),0) as avg_waiting_time'),

                DB::raw('SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN TIMESTAMPDIFF(MINUTE, order_reports.order_picked_at, order_reports.final_status_at)
                    ELSE 0 END
                ) / NULLIF(SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN 1 ELSE 0 END
                ),0) as avg_pickup_to_delivery'),

                DB::raw('SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN TIMESTAMPDIFF(MINUTE, order_reports.order_accepted_at, order_reports.reached_shop_at)
                    ELSE 0 END +
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN TIMESTAMPDIFF(MINUTE, order_reports.reached_shop_at, order_reports.order_picked_at)
                    ELSE 0 END +
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN TIMESTAMPDIFF(MINUTE, order_reports.order_picked_at, order_reports.final_status_at)
                    ELSE 0 END
                ) / NULLIF(SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN 1 ELSE 0 END
                ),0) as avg_total_cycle'),

                DB::raw('SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN order_reports.shop_to_delivery_km
                    ELSE 0 END
                ) / NULLIF(SUM(
                    CASE WHEN order_reports.status_id = '.OrderStatus::DELIVERED.'
                    THEN 1 ELSE 0 END
                ),0) as avg_distance')
            )

            ->with([
                'client:id,user_id',
                'client.user:id,name',
                'shop:id,name'
            ])

            ->groupBy('order_reports.shop_id', 'order_reports.client_id')

            ->orderBy('client_id')
            ->orderBy('shop_id')

            ->paginate($perPage)
            ->withQueryString();
    }
}
