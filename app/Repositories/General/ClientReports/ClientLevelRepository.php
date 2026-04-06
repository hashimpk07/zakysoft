<?php

namespace App\Repositories\General\ClientReports;

use App\Interfaces\General\ClientReports\ClientLevelInterface;
use App\OrderReport;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;

use Illuminate\Pagination\LengthAwarePaginator;

class ClientLevelRepository implements ClientLevelInterface
{
    
     public function getClients(bool $active, bool $withName)
    {
        return app()->make('listInterface')->getClients(
            isActive: $active,
            withName: $withName
        );
    }

    public function getClientShops(bool $active)
    {
        return app()->make('listInterface')->getClientShops([
            'active' => $active
        ]);
    }

    public function getLevelReport(array $filters, int $perPage): LengthAwarePaginator
    {
        [$from, $to, $clientId, $shopId] = $filters;

        $delivered = OrderStatus::DELIVERED;
        $finishedOrders = OrderStatus::FINISHED;

        return OrderReport::query()
            ->select([
                DB::raw('MIN(created_at) as report_date'),
                'client_id',
                'shop_id',

                DB::raw('COUNT(*) as received_orders'),
                DB::raw("SUM(CASE WHEN status_id = {$delivered} THEN 1 ELSE 0 END) as delivered_orders"),
                DB::raw("SUM(CASE WHEN status_id != {$delivered} THEN 1 ELSE 0 END) as failed_orders"),

                DB::raw('AVG(TIMESTAMPDIFF(SECOND, order_created_at, reached_shop_at)) as avg_arrival_sec'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, reached_shop_at, order_picked_at)) as avg_waiting_sec'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, order_picked_at, final_status_at)) as avg_pickup_to_delivery_sec'),

                DB::raw("
                    AVG(
                        CASE 
                            WHEN status_id = {$delivered}
                            THEN TIMESTAMPDIFF(SECOND, order_created_at, final_status_at)
                        END
                    ) as avg_tct_sec
                "),

                DB::raw("
                    AVG(
                        CASE 
                            WHEN status_id = {$delivered} 
                            THEN GREATEST(shop_to_delivery_km,0)
                        END
                    ) as avg_distance
                "),

                DB::raw('COUNT(DISTINCT captain_id) as delivered_captains'),
            ])
            ->whereIn('status_id', $finishedOrders)
            ->whereBetween('created_at', [$from, $to])
            ->when($clientId, fn($q) => $q->where('client_id', $clientId))
            ->when($shopId, fn($q) => $q->where('shop_id', $shopId))
            ->groupBy(DB::raw('DATE(created_at)'), 'client_id', 'shop_id')
            ->orderBy('report_date', 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }
}