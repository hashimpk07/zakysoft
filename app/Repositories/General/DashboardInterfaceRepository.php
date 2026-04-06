<?php

namespace App\Repositories\General;

use App\Client;
use App\ClientShop;
use App\Interfaces\General\DashboardInterface;
use App\Order;
use App\OrderReport;
use App\OrderStatus;
use App\Quadrant;
use App\Services\General\DTO\DateRangeDTO;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardInterfaceRepository implements DashboardInterface
{
    private const NEW_ORDER_STATUSES = [
        OrderStatus::NEW_ORDER,
        OrderStatus::ORDER_PACKAGE,
        OrderStatus::ASSIGN_ATTEMPTS,
    ];

    private const ON_GOING_STATUSES = [
        OrderStatus::ACCEPT,
        OrderStatus::START_RIDE,
        OrderStatus::REACHED_SHOP,
        OrderStatus::PICKED,
        OrderStatus::PICKED_UP,
        OrderStatus::SHIPPED,
        OrderStatus::REACHED_DESTINATION,
        OrderStatus::INCOMPLETE,
        OrderStatus::PENDING,
        OrderStatus::REFUSE,
        OrderStatus::TICKET_RAISED,
        OrderStatus::REROUTED,
        OrderStatus::CLIENT_RETURN_DECLINE,
    ];

    private const UNSUCCESSFUL_STATUSES = [
        OrderStatus::CANCEL,
        OrderStatus::CLIENT_RETURN_ACCEPTED,
        OrderStatus::FORYOU_RETURN_ACCEPTED,
    ];

    private function statusIds(): array
    {
        return [
            OrderStatus::DELIVERED,
            OrderStatus::CANCEL,
            OrderStatus::RETURN_TO_CLIENT,
            OrderStatus::CANCEL_REQUEST_ACCEPTED,
            OrderStatus::CLIENT_RETURN_ACCEPTED,
        ];
    }

    public function getStats(string $fromDate, string $toDate): Collection
    {
        return Order::query()
            ->select(
                'order_statuses.name',
                'order_statuses.id',
                DB::raw('COUNT(*) as count')
            )
            ->excludeQuadrants()
            ->leftJoin('order_statuses', 'order_statuses.id', 'orders.status_id')
            ->whereIn('status_id', $this->statusIds())
            ->withinDateRange($fromDate, $toDate, 'orders.delivery_date')
            ->groupBy('order_statuses.name', 'order_statuses.id')
            ->orderBy('order_statuses.id')
            ->toBase()
            ->get();
    }

    public function getClientStats(string $fromDate, string $toDate): Collection
    {
        return Client::query()
            ->select('id', 'user_id')
            ->with('user:id,name')
            ->whereHas('order', fn($q) => $q->withinDateRange($fromDate, $toDate, 'orders.delivery_date'))
            ->withCount([
                'orders as total_orders' => fn($q) => $q
                    ->withinDateRange($fromDate, $toDate, 'orders.delivery_date'),

                'orders as new_orders' => fn($q) => $q
                    ->whereIn('status_id', self::NEW_ORDER_STATUSES)
                    ->withinDateRange($fromDate, $toDate, 'orders.delivery_date'),

                'orders as on_going_orders' => fn($q) => $q
                    ->whereIn('status_id', self::ON_GOING_STATUSES)
                    ->withinDateRange($fromDate, $toDate, 'orders.delivery_date'),

                'orders as delivered' => fn($q) => $q
                    ->where('status_id', OrderStatus::DELIVERED)
                    ->withinDateRange($fromDate, $toDate, 'orders.delivery_date'),

                'orders as unsuccessful_deliveries' => fn($q) => $q
                    ->whereIn('status_id', self::UNSUCCESSFUL_STATUSES)
                    ->withinDateRange($fromDate, $toDate, 'orders.delivery_date'),
            ])
            ->get();
    }

    public function getOverallStatistic(DateRangeDTO $range, ?int $quadrant): Collection
    {
        return OrderReport::query()
            ->select(
                'order_statuses.name',
                'order_statuses.id',
                DB::raw('COUNT(DISTINCT order_reports.id) as count'),
            )
            ->leftJoin('order_statuses', 'order_statuses.id', 'order_reports.status_id')
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->whereBetween('order_reports.final_status_at', [$range->startDate, $range->endDate])
            ->when($quadrant, fn($q) => $q->whereRaw('shop_region.quadrant_id = ?', [$quadrant]))
            ->groupBy('order_statuses.name', 'order_statuses.id')
            ->orderBy('order_statuses.id')
            ->belongsToMe()
            ->finishedOrders()
            ->excludeQuadrants('shop_region.quadrant_id')
            ->get();
    }

    public function getOverallActiveClientsCount(DateRangeDTO $range, ?int $quadrant): int
    {
        return OrderReport::query()
            ->select(
                DB::raw('COUNT(DISTINCT order_reports.client_id) as client_count'),
                DB::raw('COALESCE(shop_region.quadrant_id, "-1") as quadrant_id')
            )
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->whereBetween('order_reports.final_status_at', [$range->startDate, $range->endDate])
            ->when($quadrant, fn($q) => $q->whereRaw('shop_region.quadrant_id = ?', [$quadrant]))
            ->finishedOrders()
            ->excludeQuadrants('shop_region.quadrant_id')
            ->belongsToMe()
            ->groupByRaw('COALESCE(shop_region.quadrant_id, "-1")')
            ->get()
            ->sum('client_count');
    }

    public function getQuadrants(): Collection
    {
        return Quadrant::select('id', 'name')->excludeQuadrants()->toBase()->get();
    }

    public function getTotalOrders(DateRangeDTO $range, ?int $clientId): int
    {
        return Order::query()
            ->belongsToMe()
            ->excludeQuadrants()
            ->when($clientId, fn($q) => $q->where('client_id', $clientId))
            ->withinDateRange($range->startDate, $range->endDate, 'delivery_date')
            ->count();
    }

    public function getClients(DateRangeDTO $range, ?int $clientId, int $pageSize): LengthAwarePaginator
    {
        return Client::query()
            ->excludeQuadrants()
            ->select('id', 'user_id')
            ->with('user:id,name')
            ->when($clientId, fn($q) => $q->where('id', $clientId))
            ->whereHas('order', fn($q) => $q->withinDateRange($range->startDate, $range->endDate, 'delivery_date'))
            ->addSelect([
                'average_consumed_time' => OrderReport::query()
                    ->select(DB::raw('
                        DATE_FORMAT(
                            SEC_TO_TIME(AVG(TIMESTAMPDIFF(SECOND,
                                order_created_at,
                                IFNULL(final_status_at, NOW())
                            ))), "%H:%i:%s") as processing_time
                    '))
                    ->whereIn('status_id', OrderStatus::FINISHED)
                    ->whereColumn('order_reports.client_id', 'clients.id')
                    ->withinDateRange($range->startDate, $range->endDate)
                    ->limit(1),
            ])
            ->addSelect([
                'has_order_shops' => Order::query()
                    ->select(DB::raw('COUNT(DISTINCT shopname)'))
                    ->whereColumn('orders.client_id', 'clients.id')
                    ->withinDateRange($range->startDate, $range->endDate, 'delivery_date')
                    ->limit(1),
            ])
            ->withCount([
                'orders as total_orders' => fn($q) => $q
                    ->excludeQuadrants()
                    ->withinDateRange($range->startDate, $range->endDate, 'delivery_date'),

                'orders as new_orders' => fn($q) => $q
                    ->excludeQuadrants()
                    ->whereIn('status_id', self::NEW_ORDER_STATUSES)
                    ->withinDateRange($range->startDate, $range->endDate, 'delivery_date'),

                'orders as on_going_orders' => fn($q) => $q
                    ->excludeQuadrants()
                    ->whereIn('status_id', self::ON_GOING_STATUSES)
                    ->withinDateRange($range->startDate, $range->endDate, 'delivery_date'),

                'orders as delivered' => fn($q) => $q
                    ->excludeQuadrants()
                    ->where('status_id', OrderStatus::DELIVERED)
                    ->withinDateRange($range->startDate, $range->endDate, 'delivery_date'),

                'orders as unsuccessful_deliveries' => fn($q) => $q
                    ->excludeQuadrants()
                    ->whereIn('status_id', self::UNSUCCESSFUL_STATUSES)
                    ->withinDateRange($range->startDate, $range->endDate, 'delivery_date'),
            ])
            ->belongsToMe()
            ->paginate($pageSize);
    }

    public function getClientDashboardStatistic(DateRangeDTO $range, ?int $clientId): Collection
    {
        return OrderReport::query()
            ->select(
                'order_statuses.name',
                'order_statuses.id',
                DB::raw('COUNT(DISTINCT order_reports.id) as count'),
            )
            ->leftJoin('order_statuses', 'order_statuses.id', 'order_reports.status_id')
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->when($clientId, fn($q) => $q->where('order_reports.client_id', $clientId))
            ->whereBetween('order_reports.final_status_at', [$range->startDate, $range->endDate])
            ->groupBy('order_statuses.name', 'order_statuses.id')
            ->orderBy('order_statuses.id')
            ->belongsToMe()
            ->excludeQuadrants('shop_region.quadrant_id')
            ->finishedOrders()
            ->get();
    }

    public function getActiveBranchCount(DateRangeDTO $range, ?int $clientId): int
    {
        return OrderReport::query()
            ->select(DB::raw('COUNT(DISTINCT order_reports.shop_id) as branch_count'))
            ->when($clientId, fn($q) => $q->where('order_reports.client_id', $clientId))
            ->whereBetween('order_reports.final_status_at', [$range->startDate, $range->endDate])
            ->belongsToMe()
            ->finishedOrders()
            ->excludeQuadrants()
            ->value('branch_count') ?? 0;
    }

    public function getActiveClients(DateRangeDTO $range): Collection
    {
        return Client::query()
            ->isActive()
            ->excludeQuadrants()
            ->with('user:id,name')
            ->whereHas('order', fn($q) => $q->withinDateRange($range->startDate, $range->endDate))
            ->get();
    }

    public function getClientShops(DateRangeDTO $range, ?int $clientId, ?int $shopId, int $pageSize = 10): LengthAwarePaginator
    {
        return ClientShop::query()
            ->excludeQuadrants()
            ->select('id', 'name')
            ->isActive()
            ->when($clientId, fn($q) => $q->where('client_shops.client_id', $clientId))
            ->when($shopId,   fn($q) => $q->where('client_shops.id', $shopId))
            ->whereHas('orders', fn($q) => $q->withinDateRange($range->startDate, $range->endDate, 'delivery_date'))
            ->addSelect([
                'average_consumed_time' => OrderReport::query()
                    ->select(DB::raw('
                        DATE_FORMAT(
                            SEC_TO_TIME(AVG(TIMESTAMPDIFF(SECOND,
                                order_created_at,
                                IFNULL(final_status_at, NOW())
                            ))), "%H:%i:%s") as processing_time
                    '))
                    ->whereIn('status_id', OrderStatus::FINISHED)
                    ->whereColumn('order_reports.shop_id', 'client_shops.id')
                    ->withinDateRange($range->startDate, $range->endDate)
                    ->limit(1),
            ])
            ->withCount([
                'orders as total_orders' => fn($q) => $q
                    ->withinDateRange($range->startDate, $range->endDate, 'delivery_date'),

                'orders as new_orders' => fn($q) => $q
                    ->whereIn('status_id', self::NEW_ORDER_STATUSES)
                    ->withinDateRange($range->startDate, $range->endDate, 'delivery_date'),

                'orders as on_going_orders' => fn($q) => $q
                    ->whereIn('status_id', self::ON_GOING_STATUSES)
                    ->withinDateRange($range->startDate, $range->endDate, 'delivery_date'),

                'orders as delivered' => fn($q) => $q
                    ->where('status_id', OrderStatus::DELIVERED)
                    ->withinDateRange($range->startDate, $range->endDate, 'delivery_date'),

                'orders as unsuccessful_deliveries' => fn($q) => $q
                    ->whereIn('status_id', self::UNSUCCESSFUL_STATUSES)
                    ->withinDateRange($range->startDate, $range->endDate, 'delivery_date'),
            ])
            ->paginate($pageSize);
    }
}