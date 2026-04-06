<?php

namespace App\Repositories\General;

use App\Cache\Order as CacheOrder;
use App\DeliveryType;
use App\Filter\OrderFilter;
use App\Http\Requests\General\Orders\ClientShopOrderRequest;
use App\Http\Requests\General\Orders\OrderListRequest;
use App\Http\Requests\General\Orders\ShopOrderRequest;
use App\Interfaces\General\OrderInterface;
use App\Order;
use App\OrderStatus;
use App\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;

class OrderInterfaceRepository implements OrderInterface
{
    public function getOrders(OrderListRequest $request): LengthAwarePaginator
    {
        $filterStatuses = $request->filterStatuses();

        return Order::query()
            ->select(
                'orders.code',
                'orders.client_order_id',
                'orders.amount',
                'orders.delivery_charge',
                'orders.delivery_date',
                'orders.created_at',
                'orders.status_id',
                'orders.id',
                'orders.delivery_time',
                'orders.client_id',
                'orders.captain_id',
                'orders.zone_id',
                'orders.region_id',
                'orders.shopname',
                'orders.delivery_type',
                'orders.scheduled_delivery_time_slot_id',
                'orders.dispatch_at',
            )
            ->with([
                'shop:id,name,express_time,zone_id,express_auto_assign_rule_id,auto_assignable',
                'shop.zone:id,name',
                'shop.region:regions.id,regions.name',
                'shop.dispatchRuleForExpress',
                'timeSlot',
                'progress:id,name',
                'captain:id,phone_number,user_id',
                'captain.user:id,name',
            ])
            ->with([
                'openTicket' => fn($q) => $q->withCount('notUserSeenMessages'),
                'openComplaint' => fn($q) => $q->withCount('notUserSeenMessages'),
            ])
            ->where(
                fn($q) => $q
                    ->where([
                        ['delivery_type', '=', DeliveryType::SCHEDULES],
                        ['dispatch_at', '<=', now()->format('Y-m-d H:i:s')],
                    ])
                    ->orWhere('delivery_type', '=', DeliveryType::EXPRESS)
            )
            ->when(
                $this->onlyHasStatuses($filterStatuses, [OrderStatus::ORDER_PACKAGE, OrderStatus::ASSIGN_ATTEMPTS]),
                fn($q) => $q->with('package.package')
            )
            ->when(
                $this->onlyHasStatuses($filterStatuses, [OrderStatus::NEW_ORDER]),
                fn($q) => $q->whereHas('shop', fn($q) => $q->where('auto_assignable', 0))
            )
            ->withCount('assignAttempts')
            ->withLastLog()
            ->withRegionZone()
            ->WithClient()
            ->withShop()
            ->belongsToMe()
            ->filter(new OrderFilter($request))
            ->latest()
            ->paginate($request->perPage())
            ->withQueryString();
    }

    private function onlyHasStatuses(array $filterStatuses, array $allowed): bool
    {
        return !empty($filterStatuses)
            && empty(array_diff($filterStatuses, $allowed));
    }

    public function getOrderCounts(OrderListRequest $request): array
    {
        $filter = new OrderFilter($request);

        return [
            'new_orders' => $this->baseOrderQuery($filter)
                ->where($this->dispatchQuery())
                ->whereHas('shop', fn($q) => $q->where('auto_assignable', 0))
                ->where('status_id', OrderStatus::NEW_ORDER)
                ->count(),

            'assign_attempts' => $this->baseOrderQuery($filter)
                ->where($this->dispatchQuery())
                ->whereHas('shop', fn($q) => $q->where('auto_assignable', 1))
                ->whereIn('status_id', [
                    OrderStatus::ASSIGN_ATTEMPTS,
                    OrderStatus::ORDER_PACKAGE,
                    OrderStatus::NEW_ORDER,
                ])
                ->count(),

            'on_going' => $this->baseOrderQuery($filter)
                ->whereIn('status_id', [
                    OrderStatus::ACCEPT,
                    OrderStatus::START_RIDE,
                    OrderStatus::REACHED_SHOP,
                    OrderStatus::PICKED,
                    OrderStatus::PICKED_UP,
                    OrderStatus::SHIPPED,
                    OrderStatus::REACHED_DESTINATION,
                    OrderStatus::REROUTED,
                ])
                ->count(),

            'complaints' => $this->baseOrderQuery($filter)
                ->whereIn('status_id', [OrderStatus::TICKET_RAISED, OrderStatus::PENDING])
                ->count(),

            'client_return' => $this->baseOrderQuery($filter)
                ->where('status_id', OrderStatus::RETURN_TO_CLIENT)
                ->count(),

            'request_for_cancel' => $this->baseOrderQuery($filter)
                ->where('status_id', OrderStatus::REQUEST_FOR_CANCEL)
                ->count(),
        ];
    }

    private function baseOrderQuery(OrderFilter $filter): Builder
    {
        return Order::belongsToMe()->filter($filter, [], ['status']);
    }

    private function dispatchQuery(): Closure
    {
        return fn($q) => $q
            ->where([
                ['delivery_type', '=', DeliveryType::SCHEDULES],
                ['dispatch_at', '<=', now()->format('Y-m-d H:i:s')],
            ])
            ->orWhere('delivery_type', '=', DeliveryType::EXPRESS);
    }

    public function getScheduledOrders(OrderListRequest $request): LengthAwarePaginator
    {
        return Order::select(
            'orders.code',
            'orders.client_order_id',
            'orders.amount',
            'orders.delivery_charge',
            'orders.delivery_date',
            'orders.created_at',
            'orders.status_id',
            'orders.id',
            'orders.delivery_time',
            'orders.client_id',
            'orders.captain_id',
            'orders.zone_id',
            'orders.region_id',
            'orders.shopname',
            'orders.delivery_type',
            'orders.scheduled_delivery_time_slot_id',
            'orders.dispatch_at',
        )
            ->with([
                'shop:id,name,express_time,zone_id',
                'shop.zone:id,name',
                'shop.region:regions.id,regions.name',
                'timeSlot',
                'progress:id,name',
                'openTicket:id,order_id,type',
                'openComplaint:id,order_id,type',
            ])
            ->where('delivery_type', DeliveryType::SCHEDULES)
            ->withLastLog()
            ->withRegionZone()
            ->withCaptain()
            ->withClient()
            ->belongsToMe()
            ->filter(new OrderFilter($request))
            ->orderBy('orders.id', 'desc')
            ->paginate($request->perPage())
            ->withQueryString();
    }

    public function getClientShopOrders(ClientShopOrderRequest $request): LengthAwarePaginator
    {
        $query = DB::table('client_shops')
            ->select(
                'client_shops.id',
                'client_users.name as client_name',
                'client_shops.name',
                'zones.name as zone_name',
                'regions.name as region_name',
                'quadrants.name as quadrant_name',
                DB::raw('COUNT(*) as open_orders_count'),
            )
            ->leftJoin('orders', 'client_shops.id', '=', 'orders.shopname')
            ->leftJoin('clients', 'client_shops.client_id', '=', 'clients.id')
            ->leftJoin('users as client_users', 'clients.user_id', '=', 'client_users.id')
            ->leftJoin('zones', 'client_shops.zone_id', '=', 'zones.id')
            ->leftJoin('regions', 'zones.region_id', '=', 'regions.id')
            ->leftJoin('quadrants', 'regions.quadrant_id', '=', 'quadrants.id')
            ->when(
                $request->status(),
                fn($q) => $q->where('orders.status_id', $request->status()),
                fn($q) => $q->whereIn('orders.status_id', OrderStatus::OPEN_STATUSES),
            );

        $query = $this->applyDataPermissions($query);

        return $query
            ->groupBy(
                'client_shops.name',
                'client_name',
                'client_shops.id',
                'zone_name',
                'region_name',
                'quadrant_name',
            )
            ->havingRaw('open_orders_count > 0')
            ->when($request->clients(), fn($q) => $q->whereIn('client_shops.client_id', $request->clients()))
            ->when($request->clientShops(), fn($q) => $q->whereIn('client_shops.id', $request->clientShops()))
            ->when($request->region(), fn($q) => $q->where('quadrants.id', $request->region()))
            ->when($request->area(), fn($q) => $q->where('regions.id', $request->area()))
            ->when($request->zone(), fn($q) => $q->where('zones.id', $request->zone()))
            ->orderBy('open_orders_count', 'desc')
            ->paginate($request->perPage())
            ->withQueryString();
    }

    private function applyDataPermissions(QueryBuilder $query): QueryBuilder
    {
        $user = auth()->user();
        $permission = $user->data_permission;
        $ids = $user->dataPermission()->pluck('id');

        return match ($permission) {
            User::DATA_PERMISSION_BRANCH_BASED => $query->whereIn('orders.shopname', $ids),
            User::DATA_PERMISSION_ZONE_BASED => $query->whereIn('zones.id', $ids),
            User::DATA_PERMISSION_CLIENT_BASED => $query->whereIn('orders.client_id', $ids),
            User::DATA_PERMISSION_REGION_BASED => $query->whereIn('quadrants.id', $ids),
            default => $query,
        };
    }

    public function getShopOrders(int $shopId, ShopOrderRequest $request): LengthAwarePaginator
    {
        return Order::belongsToMe()
            ->select('id', 'client_order_id', 'created_at', 'status_id', 'delivery_payment_mode', 'delivery_date', 'delivery_type')
            ->with('progress:id,name', 'payment')
            ->with(['shop' => fn($q) => $q->withClient()])
            ->where('shopname', $shopId)
            ->WithCaptain()
            ->withShop()
            ->when($request->captain(), fn($q) => $q->where('captain_id', $request->captain()))
            ->when($request->statuses(), fn($q) => $q->where('status_id', $request->statuses()))
            ->when($request->search(), fn($q) => $q->whereLike(['id', 'client_order_id'], $request->search()))
            ->open()
            ->orderBy('orders.id', 'asc')
            ->paginate($request->perPage())
            ->withQueryString();
    }

      public function getLiveOrders(array $request, $perPage = 10): LengthAwarePaginator
    {
        return (new CacheOrder())
            ->withUserPermission()
            ->filter($request)
            ->paginate($perPage);
    }
    public function getLiveOrderCount(array $filters)
    {
        return (new CacheOrder())
            ->withUserPermission()
            ->filter($filters)
            ->count();
    }

    public function getPendingOrders(array $filters): LengthAwarePaginator
    {
        return Order::select(
                'code', 'client_order_id', 'amount', 'delivery_charge',
                'delivery_date', 'created_at', 'status_id', 'id',
                'delivery_time', 'client_id', 'captain_id', 'zone_id',
                'region_id', 'shopname', 'delivery_type',
                'scheduled_delivery_time_slot_id'
            )
            ->with([
                'captain.user',
                'zone:id,name,region_id',
                'client.user',
                'shop',
                'logsExcept',
                'timeSlot',
                'progress',
                'deliveredOrderLogs',
            ])
            ->where('status_id', OrderStatus::PENDING)
            ->when($filters['from_date'] ?? null, function ($q, $fromDate) use ($filters) {
                $q->unless($filters['orderID'] ?? null, function ($q) use ($fromDate) {
                    $date = date('Y-m-d', strtotime($fromDate));
                    $q->where(fn($q) => $q
                        ->where([['created_at', '>=', "{$date} 00:00:00"], ['delivery_type', '=', Order::DELIVERY_TYPE_FAST]])
                        ->orWhere([['delivery_date', '>=', "{$date} 00:00:00"], ['delivery_type', '=', Order::DELIVERY_TYPE_SCHEDULE]])
                    );
                });
            })
            ->when($filters['to_date'] ?? null, function ($q, $toDate) use ($filters) {
                $q->unless($filters['orderID'] ?? null, function ($q) use ($toDate) {
                    $date = date('Y-m-d', strtotime($toDate));
                    $q->where(fn($q) => $q
                        ->where([['created_at', '<=', "{$date} 23:59:59"], ['delivery_type', '=', Order::DELIVERY_TYPE_FAST]])
                        ->orWhere([['delivery_date', '<=', "{$date} 23:59:59"], ['delivery_type', '=', Order::DELIVERY_TYPE_SCHEDULE]])
                    );
                });
            })
            ->when($filters['orderID']  ?? null, fn($q, $v) => $q->where('client_order_id', 'LIKE', "{$v}%"))
            ->when($filters['shopname'] ?? null, fn($q, $v) => $q->whereIn('shopname', (array) $v))
            ->when($filters['captain']  ?? null, fn($q, $v) => $q->where('captain_id', $v))
            ->when($filters['zone']     ?? null, fn($q, $v) => $q->where('zone_id', $v))
            ->when(Auth::user()->data_permission === 'branch_based', fn($q) =>
                $q->whereIn('shopname', DB::table('emp_permission_zones_branches')->where('user_id', Auth::id())->pluck('branch_id'))
            )
            ->when(Auth::user()->data_permission === 'zone_based', fn($q) =>
                $q->whereIn('zone_id', DB::table('emp_permission_zones_branches')->where('user_id', Auth::id())->pluck('zone_id'))
            )
            ->orderBy('id', 'desc')
            ->when($filters['orderID'] ?? null, fn($q) => $q->orderBy('client_order_id', 'asc'))
            ->paginate(100)
            ->withQueryString();
    }
}
