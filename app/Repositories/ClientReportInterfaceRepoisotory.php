<?php
namespace App\Repositories;

use App\Interfaces\ClientReportInterface;
use App\OrderReport;
use App\OrderStatus;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ClientReportInterfaceRepoisotory implements ClientReportInterface
{
    public function getLevelReport(array $filters = [], int $perPage = 100): LengthAwarePaginator
    {
        [$from, $to, $clientId, $shopId] = array_pad($filters, 4, null);

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

                // prevent negative distance and fetch only delivered orders
                DB::raw("AVG(CASE WHEN status_id = {$delivered} THEN GREATEST(shop_to_delivery_km, 0)
                            ELSE NULL
                            END
                            ) as avg_distance"),

                DB::raw('COUNT(DISTINCT captain_id) as delivered_captains'),
            ])
            ->whereIn('status_id', $finishedOrders)
            ->whereBetween('created_at', [$from, $to])
            ->when($clientId, fn($q) => $q->where('client_id', $clientId))
            ->when($shopId, fn($q) => $q->where('shop_id', $shopId))
            // GROUPING
            ->groupBy(DB::raw('DATE(created_at)'), 'client_id', 'shop_id')
            // Relations
            ->with([
                'shop' => fn($q) => $q->select('id', 'zone_id', 'name')->with([
                    'zone' => fn($z) => $z->select('id', 'name'),
                ]),
                'client' => fn($q) => $q
                    ->select('id') // required for relation
                    ->withName(), // scope that selects name
            ])
            // Sorting (latest date first)
            ->orderBy('report_date', 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }
}
