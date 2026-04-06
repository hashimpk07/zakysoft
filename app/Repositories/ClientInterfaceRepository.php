<?php
namespace App\Repositories;

use App\Client;
use App\ClientShop;
use App\Filter\Client\ClientReportFilter;
use App\Filter\OrderFilter;
use App\GeneralExport;
use App\Interfaces\ClientInterface;
use App\Order;
use App\OrderStatus;
use App\SalesReport;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ClientInterfaceRepository implements ClientInterface
{
    public function getClientOrdersQuery(int $clientId, array $shopIds)
    {
        return Order::query()->where('client_id', $clientId)->whereIn('shopname', $shopIds);
    }

    public function getStatusSummary($query)
    {
        return $query->clone()->selectRaw('COUNT(*) as count, order_statuses.name as status')->leftJoin('order_statuses', 'order_statuses.id', '=', 'orders.status_id')->groupBy('order_statuses.name')->orderBy('order_statuses.name')->get();
    }

    public function countByStatus($query, ?int $statusId = null, ?string $startDate = null, ?string $endDate = null): int
    {
        return $query
            ->clone()
            ->when($statusId !== null, fn($q) => $q->where('status_id', $statusId))
            ->when($startDate && $endDate, fn($q) => $q->withinDateRange($startDate, $endDate))
            ->count();
    }

    public function baseUserQuery($user, Request $request)
    {
        return Order::belongsToMe();
    }

    public function countNewOrders($user, $request)
    {
        return $this->baseUserQuery($user, $request)
            ->whereIn('status_id', [OrderStatus::NEW_ORDER, OrderStatus::ORDER_PACKAGE, OrderStatus::ASSIGN_ATTEMPTS])
            ->count();
    }

    public function countAssignableAttempts($user, $request)
    {
        return $this->baseUserQuery($user, $request)
            ->whereHas('shop', fn($q) => $q->where('auto_assignable', 1))
            ->whereIn('status_id', [OrderStatus::ASSIGN_ATTEMPTS, OrderStatus::ORDER_PACKAGE, OrderStatus::NEW_ORDER])
            ->count();
    }

    public function countOngoing($user, $request)
    {
        return $this->baseUserQuery($user, $request)->whereIn('status_id', OrderStatus::ON_GOING_ORDER)->count();
    }

    public function countByStatusList($user, $request, array $statusIds)
    {
        return $this->baseUserQuery($user, $request)->whereIn('status_id', $statusIds)->count();
    }

    public function salesReportQuery(int $clientId, bool $onlyBaseTable = false)
    {
        $salesReport = new SalesReport();
        $salesReport->setClient($clientId);

        return $salesReport->query($onlyBaseTable);
    }

    public function getOrders(int $clientId, Request $request, int $page = 20): LengthAwarePaginator
    {
        return Order::query()
            ->where('client_id', $clientId)
            ->select(['id', 'client_order_id', 'amount', 'delivery_charge', 'created_at', 'status_id', 'captain_id', 'zone_id', 'region_id', 'shopname'])
            ->with([
                'shop',
                'shop.zone:id,name',
                'shop.region:regions.id,regions.name',
                'zone',
                'region',
                'captain.user',
                'progress',
                'openComplaint' => function ($query) {
                    $query->withCount('notCaptainSeenMessages');
                }
            ])
            ->withLastLog()
            ->filter(new OrderFilter($request))
            ->withRegionZone()
            ->orderByDesc('id')
            ->paginate($page)
            ->withQueryString();
    }

    public function getOrderById(array $shopIds, int $orderId): ?Order
    {
        return Order::whereKey($orderId)
            ->whereIn('shopname', $shopIds)
            // ->with(['shop', 'shop.zone:id,name', 'shop.region:regions.id,regions.name', 'zone', 'region', 'captain.user', 'progress', 'logsExecpt.progress'])
            ->with([
                'progress',
                'captain' => fn($q) =>
                    $q->select(['id', 'user_id', 'phone_number'])
                        ->withName(),
                'client' => fn($q) =>
                    $q->select(['id', 'user_id'])->withName(),
                'shop:id,name',
            ])

            ->firstOrFail();
    }

    public function getClientReports(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = Order::query()
            ->select($this->clientReportSelect())

            // core
            ->leftJoin('order_reports', 'orders.id', '=', 'order_reports.order_id')

            // client
            ->leftJoin('clients', 'orders.client_id', '=', 'clients.id')
            ->leftJoin('users as client_users', 'clients.user_id', '=', 'client_users.id')

            ->leftJoin('client_shops', 'client_shops.id', '=', 'order_reports.shop_id')
            ->leftJoin('zones', 'zones.id', '=', 'order_reports.zone_id')
            ->leftJoin('regions', 'regions.id', '=', 'order_reports.region_id')
            ->leftJoin('quadrants', 'quadrants.id', '=', 'regions.quadrant_id')

            // captain
            ->leftJoin('captains', 'captains.id', '=', 'order_reports.captain_id')
            ->leftJoin('users as captain_users', 'captains.user_id', '=', 'captain_users.id')

            // status
            ->leftJoin('order_statuses', 'orders.status_id', '=', 'order_statuses.id')
            ->belongsToMe();

        $query = (new ClientReportFilter($filters))->apply($query);

        return $query

            ->with(['pendingReasonLog.reason', 'lastLog.createdBy'])
            ->withLastLog('lastLog.createdBy')

            ->whereIn('orders.status_id', [
                OrderStatus::DELIVERED,
                OrderStatus::CANCEL,
                OrderStatus::RETURN_TO_CLIENT,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
                OrderStatus::CANCEL_REQUEST_ACCEPTED,
                OrderStatus::FORYOU_RETURN_ACCEPTED,
            ])
            ->orderByDesc('orders.delivery_date')
            ->paginate($perPage)
            ->withQueryString();
    }

    private function clientReportSelect(): array
    {
        return [
            // core
            'orders.id',
            'orders.client_order_id',
            'orders.delivery_type as order_type',
            'orders.created_at',
            'orders.shop_to_delivery_km as distance',

            // client
            'client_users.name as client_name',

            // shop & location
            'client_shops.name as shop_name',
            'zones.name as zone_name',
            'regions.name as region_name',
            'quadrants.name as quadrant_name',

            // captain
            'captain_users.name as captain_name',

            // status
            'order_statuses.name as order_status_name',

            // report timestamps
            'order_reports.order_accepted_at',
            'order_reports.start_ride_at',
            'order_reports.reached_shop_at',
            'order_reports.order_picked_at',
            'order_reports.shipped_at',
            'order_reports.reached_dest_at',
            'order_reports.final_status_at',
            'order_reports.cod_amount',

            // misc
            'order_reports.cancellation_reason',
            'order_reports.assigned_by',
        ];
    }

    public function salesReportDataQuery(int $clientId, bool $onlyBaseTable = false, $fromDate = null, $toDate = null, $search = null)
    {

        $query = $this->salesReportQuery($clientId, $onlyBaseTable);
        if ($fromDate && $toDate) {
            $query->whereBetween('orders.created_at', [$fromDate, $toDate]);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('orders.code', 'LIKE', "%$search%")
                    ->orWhere('orders.client_order_id', 'LIKE', "%$search%")
                    ->orWhere('orders.id', $search);
            });
        }

        return $query;
    }

    public function getOrderStatusGraphCount($query)
    {
        return $query->select(DB::raw("COUNT(*) as count"), DB::raw("order_statuses.name as status"))
            ->leftJoin('order_statuses', 'order_statuses.id', 'orders.status_id')
            ->groupBy('order_statuses.name')
            ->orderBy('order_statuses.name')
            ->toBase()
            ->get();
    }

    public function getDeliveredOrdersGroupedByMonth(
        int $clientId,
        array $shopIds,
        string $startDate,
        string $endDate
    ) {
        return DB::table('orders')
            ->select(
                DB::raw('DATE_FORMAT(DATE_SUB(created_at, INTERVAL 6 HOUR), "%m") as month'),
                DB::raw('COUNT(*) as count')
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('status_id', OrderStatus::DELIVERED)
            ->where('client_id', $clientId)
            ->whereIn('shopname', $shopIds)
            ->groupBy(DB::raw('DATE_FORMAT(DATE_SUB(created_at, INTERVAL 6 HOUR), "%m")'))
            ->get();
    }

    public function findBelongsToUser(User $user, int $orderId): ?Order
    {
        return Order::belongsToMe($user)->find($orderId);
    }

    public function updateStatus(Order $order, int $statusId): bool
    {
        $order->status_id = $statusId;
        return $order->save();
    }

    public function getClientsAndShops(User $user): array
    {
        $clients = collect([]);
        $shops = collect([]);

        $employeeClient = $user->employeeClient->first();

        if ($employeeClient && $user->employeeClient->isNotEmpty()) {

            $clients = Client::select([
                'clients.id as client_id',
                'users.name as name',
                'clients.user_id',
                'clients.owner_name as user_name',
            ])
                ->leftJoin('users', 'clients.user_id', '=', 'users.id')
                ->where('clients.user_id', $employeeClient->user_id)
                ->get();

            $shops = $user->clientShops()->filter(function ($shop) {
                return count($shop->deliveryTypes) > 0;
            });
        }

        if (!$employeeClient) {
            $clients = Client::all();
            $shops = ClientShop::has('deliveryTypes')->get();
        }

        if ($shops->isNotEmpty()) {
            $shops->load('deliveryTypes', 'timeSlots');
        }

        return [
            'clients' => $clients,
            'shops' => $shops,
        ];
    }
    public function exportClientOrderCreate(array $data): GeneralExport
    {
        return GeneralExport::create($data);
    }
}
