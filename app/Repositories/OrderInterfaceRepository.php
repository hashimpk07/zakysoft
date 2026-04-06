<?php

namespace App\Repositories;

use App\Cache\Order as CacheOrder;
use App\Captain;
use App\DeliveryType;
use App\Events\OrderStatusChanged;
use App\Filter\OrderFilter;
use App\Interfaces\OrderInterface;
use App\Note;
use App\Order;
use App\OrderStatus;
use App\PackageOrder;
use App\User;
use App\Vat;
use Carbon\Carbon;
use Facades\App\Services\OrderStatusLog;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderInterfaceRepository implements OrderInterface
{
    public function createOrder($data)
    {
        return Order::create($data);
    }

    public function addNotesToOrder($order, $note, $user)
    {
        $notes = Note::create([
            'note' => $note,
        ]);

        $notes->user()->associate($user);
        $notes->order()->associate($order);
        $notes->save();

        return $notes;
    }

    public function getOnlineCaptains(array $filters, int $perPage)
    {
        $query = Captain::query()
            ->select(['captains.id', 'captains.user_id']) // only needed columns
            ->join('users', 'users.id', '=', 'captains.user_id') // for ordering/filtering
            ->with(['user:id,name', 'vehicle:id,assigned_to,type'])
            ->withCount([
                'deliveredOrders' => function ($q) {
                    [$from, $to] = $this->getDeliveryDateRange();
                    $q->whereBetween('delivery_date', [$from, $to]);
                },
            ]);

        // Filter by "online only"
        if (($filters['filter_type'] ?? 'All') !== 'All') {
            $query->online();
        }

        // Search by name
        if (!empty($filters['name'])) {
            $query->where('users.name', 'LIKE', "%{$filters['name']}%");
        }

        // Fast sorting by user name
        $query->orderBy('users.name');

        return $query->paginate($perPage);
    }

    private function getDeliveryDateRange(): array
    {
        $now = now();
        $today9am = $now->copy()->setTime(9, 0);

        if ($now->greaterThan($today9am)) {
            return [$now->format('Y-m-d') . ' 08:00', $now->copy()->addDay()->format('Y-m-d') . ' 07:59'];
        }

        return [$now->copy()->subDay()->format('Y-m-d') . ' 08:00', $now->format('Y-m-d') . ' 07:59'];
    }

    public function updateOrderStatus(Order $order, int $statusId, $loggedUser): void
    {
        switch ($statusId) {
            case OrderStatus::DELIVERED:
                $this->handleDeliveredStatus($order, $loggedUser);
                break;

            case OrderStatus::NOT_ASSIGNED:
            case OrderStatus::NEW_ORDER:
                $this->handleNewOrNotAssignedStatus($order);
                break;

            case OrderStatus::CANCEL:
                $order->update(['status_id' => $statusId]);
                break;

            default:
                $order->update(['status_id' => $statusId]);
                break;
        }
    }

    /**
     * Handle DELIVERED status updates
     */
    private function handleDeliveredStatus(Order $order, $loggedUser): void
    {
        $vat = Vat::where('status', 'active')->latest('id')->first();

        $order->update([
            'status_id' => OrderStatus::DELIVERED,
            'created_by' => $loggedUser->id,
            'vat_rate' => $vat?->rate,
        ]);
    }

    /**
     * Handle NEW_ORDER or NOT_ASSIGNED status updates
     */
    private function handleNewOrNotAssignedStatus(Order $order): void
    {
        $order->update([
            'status_id' => OrderStatus::NEW_ORDER,
            'captain_id' => null,
        ]);

        PackageOrder::where('order_id', $order->id)->delete();
        $order->pickedOrders()->detach();
    }

    public function handleReschedule(Order $order, int &$statusId, Request $request, ?int $reasonId = null): bool
    {
        // Implement reschedule logic here
        $pickupTime = $request->input('pickup_time');
        $rescheduleDate = $request->input('reschedule_date');
        $autoAssignTime = $request->input('auto_assign_time');

        // Only proceed if order is pending and both pickup and reschedule date are provided
        if ($statusId !== OrderStatus::PENDING || !$pickupTime || !$rescheduleDate) {
            return false;
        }

        // Determine dispatch datetime
        $dispatchTime = Carbon::parse($rescheduleDate . ' ' . ($autoAssignTime ?? $pickupTime));

        // Adjust 15 minutes earlier if auto assign time not provided
        if (!$autoAssignTime) {
            $dispatchTime->subMinutes(15);
        }

        // Update order
        $order->update([
            'dispatch_at' => $dispatchTime,
            'status_id' => OrderStatus::RESCHEDULED,
            'delivery_type' => Order::DELIVERY_TYPE_SCHEDULE,
        ]);

        // Update statusId for further processing
        $statusId = OrderStatus::RESCHEDULED;

        // Log status change
        $note = "Rescheduled to {$dispatchTime->format('Y-m-d H:i:s')} Pickup at {$pickupTime}";
        OrderStatusLog::log($statusId, null, $order->id, $reasonId, $note, $request->input('canceled_by'));

        // Dispatch event
        OrderStatusChanged::dispatch($order);

        return true;
    }

    public function getOrdersWithCaptain(array $orderIds): Collection
    {
        return Order::with('captain')->whereIn('id', $orderIds)->get();
    }

    public function getScheduledOrders(OrderFilter $filters, int $perPage): LengthAwarePaginator
    {
        return Order::select('orders.code', 'orders.client_order_id', 'orders.amount', 'orders.delivery_charge', 'orders.delivery_date', 'orders.created_at', 'orders.status_id', 'orders.id', 'orders.delivery_time', 'orders.client_id', 'orders.captain_id', 'orders.zone_id', 'orders.region_id', 'orders.shopname', 'orders.delivery_type', 'orders.scheduled_delivery_time_slot_id', 'orders.dispatch_at')
            ->with(['shop:id,name,express_time,zone_id', 'shop.zone:id,name', 'shop.region:regions.id,regions.name', 'timeSlot', 'progress:id,name', 'openTicket:id,order_id,type', 'openComplaint:id,order_id,type'])
            ->where('delivery_type', DeliveryType::SCHEDULES)
            ->withLastLog()
            ->withRegionZone()
            ->withCaptain()
            ->withClient()
            ->belongsToMe()
            ->filter($filters)
            ->orderBy('orders.id', 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getConsolidatedOrders(array $request, int $perPage, $user): LengthAwarePaginator
    {
        $permissionIds = $user->dataPermission()->pluck('id');

        return DB::table('client_shops')
            ->select(['client_shops.id', 'client_users.name AS client_name', 'client_shops.name', 'zones.name AS zone_name', 'regions.name AS region_name', 'quadrants.name AS quadrant_name', DB::raw('COUNT(*) AS open_orders_count')])
            ->leftJoin('orders', 'client_shops.id', '=', 'orders.shopname')
            ->leftJoin('clients', 'client_shops.client_id', '=', 'clients.id')
            ->leftJoin('users AS client_users', 'clients.user_id', '=', 'client_users.id')
            ->leftJoin('zones', 'client_shops.zone_id', '=', 'zones.id')
            ->leftJoin('regions', 'zones.region_id', '=', 'regions.id')
            ->leftJoin('quadrants', 'regions.quadrant_id', '=', 'quadrants.id')
            ->when(request('status'), function ($q, $status) {
                $q->where('orders.status_id', $status);
            })
            ->when(!request('status'), function ($q) {
                $q->whereIn('orders.status_id', OrderStatus::OPEN_STATUSES);
            })

            ->when($user->data_permission == User::DATA_PERMISSION_BRANCH_BASED, fn($q) => $q->whereIn('orders.shopname', $permissionIds))
            ->when($user->data_permission == User::DATA_PERMISSION_ZONE_BASED, fn($q) => $q->whereIn('zones.id', $permissionIds))
            ->when($user->data_permission == User::DATA_PERMISSION_CLIENT_BASED, fn($q) => $q->whereIn('orders.client_id', $permissionIds))
            ->when($user->data_permission == User::DATA_PERMISSION_REGION_BASED, fn($q) => $q->whereIn('quadrants.id', $permissionIds))
            ->groupBy(['client_shops.id', 'client_shops.name', 'client_users.name', 'zones.name', 'regions.name', 'quadrants.name'])
            ->having('open_orders_count', '>', 0)
            ->when($request['clients'], fn($q, $c) => $q->whereIn('client_shops.client_id', $c))
            ->when($request['client_shops'], fn($q, $s) => $q->whereIn('client_shops.id', $s))
            ->when($request['region'], fn($q, $region) => $q->where('quadrants.id', $region))
            ->when($request['area'], fn($q, $area) => $q->where('regions.id', $area))
            ->when($request['zone'], fn($q, $zone) => $q->where('zones.id', $zone))
            ->orderByDesc('open_orders_count')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getSingleConsolidatedOrder(int $shopId, array $filters, int $perPage): LengthAwarePaginator
    {
        return Order::belongsToMe()
            ->with(['progress', 'captain', 'payment', 'shop' => fn($q) => $q->withClient()])
            ->where('shopname', $shopId)
            ->when($filters['captain'], fn($q, $captain) => $q->where('captain_id', $captain))
            ->when($filters['statuses'], fn($q, $statuses) => $q->where('status_id', $statuses))
            ->when($filters['search'], fn($q, $search) => $q->whereLike(['id', 'client_order_id'], $search))
            ->open()
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
