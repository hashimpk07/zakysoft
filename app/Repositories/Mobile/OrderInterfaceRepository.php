<?php
namespace App\Repositories\Mobile;

use App\Captain;
use App\CaptainCommissionPayment;
use App\DeliveryType;
use App\Filter\OrderFilter;
use App\Interfaces\ListInterface;
use App\Interfaces\Mobile\OrderInterface;
use App\Order;
use App\OrderStatus;
use App\Package;
use App\PackageDeliveryRequest;
use App\PackageRejectionReason;
use Carbon\Carbon;
use Facades\App\Services\OrderStatusLog;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderInterfaceRepository implements OrderInterface
{
    public function getOrderStatistics($fromDate, $toDate): array
    {
        $statisticQuery = Order::query()->select('order_statuses.name', 'order_statuses.id', DB::raw('COUNT(*) as count'))->leftJoin('order_statuses', 'order_statuses.id', 'orders.status_id')->whereIn('status_id', OrderStatus::FINISHED)->withinDateRange($fromDate, $toDate, 'delivery_date')->groupBy('order_statuses.name', 'order_statuses.id')->orderBy('order_statuses.id')->toBase()->get();

        $statistic = $statisticQuery->mapWithKeys(function ($item) {
            return [
                $item->id => [
                    'name' => $item->name,
                    'count' => $item->count,
                ],
            ];
        });

        $total_orders = $statistic->sum('count');
        $delivery_success = $total_orders > 0 ? round((($statistic[OrderStatus::DELIVERED]['count'] ?? 0) / ($total_orders ?? 1)) * 100, 2) : 0;
        $total_hours = now()
            ->parse($fromDate)
            ->diffInHours(now()->parse($toDate));
        $avg_orders_per_hr = $total_orders > 0 ? round($total_orders / ($total_hours + 1), 2) : 0;

        $ordersQuery = Order::query()->whereBetween('created_at', [$fromDate, $toDate]);

        $orderCount = $ordersQuery->count();

        $inProgressCount = (clone $ordersQuery)->whereIn('status_id', OrderStatus::ON_GOING_ORDER)->count();

        return [
            'statistic' => $statistic,
            'total_orders' => $total_orders,
            'delivery_success' => $delivery_success,
            'avg_orders_per_hr' => $avg_orders_per_hr,
            'inProgressOrderCount' => $inProgressCount,
        ];
    }

    public function getCaptainsCount(): array
    {
        $interface = app(ListInterface::class);

        $onlineCaptains = $interface
            ->getCaptains(
                filters: [
                    'online' => true,
                ],
            )
            ->count();

        $online_captains = $interface
            ->getCaptains(
                filters: [
                    'online' => true,
                    'active' => true,
                ],
            )
            ->count();

        $offline_captains = $interface
            ->getCaptains(
                filters: [
                    'offline' => true,
                    'active' => true,
                ],
            )
            ->count();
        return compact('onlineCaptains', 'online_captains', 'offline_captains');
    }

    public function getOrders(OrderFilter $request, int $perPage): LengthAwarePaginator
    {
        $filterStatuses = $request->request()->get('status');

        return Order::query()
            ->select('orders.code', 'orders.client_order_id', 'orders.amount', 'orders.delivery_charge', 'orders.delivery_date', 'orders.created_at', 'orders.status_id', 'orders.id', 'orders.delivery_time', 'orders.client_id', 'orders.captain_id', 'orders.zone_id', 'orders.region_id', 'orders.shopname', 'orders.delivery_type', 'orders.scheduled_delivery_time_slot_id', 'orders.dispatch_at')
            ->with(['shop:id,name,express_time,zone_id,express_auto_assign_rule_id', 'shop.zone:id,name', 'shop.region:regions.id,regions.name', 'timeSlot', 'progress:id,name', 'captain:id,phone_number,user_id', 'captain.user:id,name', 'shop.dispatchRuleForExpress'])
            ->with([
                'openTicket' => function ($query) {
                    $query->withCount('notUserSeenMessages');
                },
                'openComplaint' => function ($query) {
                    $query->withCount('notUserSeenMessages');
                },
                'client:id,proof_of_pickup'
            ])
            ->where(function ($query) {
                $query->where([['delivery_type', '=', DeliveryType::SCHEDULES], ['dispatch_at', '<=', now()->format('Y-m-d H:i:s')]])->orWhere('delivery_type', '=', DeliveryType::EXPRESS);
            })
            ->when($filterStatuses && empty(array_diff(is_array($filterStatuses) ? $filterStatuses : [$filterStatuses], [OrderStatus::ORDER_PACKAGE, OrderStatus::ASSIGN_ATTEMPTS])), function ($query) {
                $query->with('package.package');
            })
            ->when($filterStatuses && empty(array_diff(is_array($filterStatuses) ? $filterStatuses : [$filterStatuses], [OrderStatus::NEW_ORDER])), function ($query) {
                $query->whereHas('shop', function ($query) {
                    $query->where('auto_assignable', 0);
                });
            })
            ->withCount('assignAttempts')
            ->withLastLog()
            ->withRegionZone()
            ->WithClient()
            ->withShop()
            // ->belongsToMe()
            ->filter($request)
            ->latest()
            ->paginate($perPage);
    }

    public function getPackageForAcceptableOrders(Request $request, int $captainId)
    {
        return Package::with(['orders', 'shop', 'deliveryRequests'])
            ->whereHas('deliveryRequests', function ($query) use ($captainId) {
                $twentySecondsAgo = Carbon::now()->subSeconds(20)->format('Y-m-d H:i:s');
                $query->where([['captain_id', '=', $captainId], ['declined_at', '=', null], ['sended_at', '>=', $twentySecondsAgo]]);
            })
            ->whereHas('directOrders', function ($query) {
                $query->whereIn('status_id', [OrderStatus::ASSIGN_ATTEMPTS, OrderStatus::ORDER_PACKAGE, OrderStatus::WAITING_FOR_ACCEPTING]);
            })
            ->whereNull('captain_accepted_at')
            ->orderBy('created_at', 'desc')
            ->first();
    }

    public function getAcceptableOrders(int $captainId)
    {
        return Order::select('id', 'client_order_id')
            ->where('status_id', OrderStatus::WAITING_FOR_ACCEPTING)
            ->where('captain_id', $captainId)
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'client_order_id' => $order->client_order_id,
                    'remaining_time' => $order->acceptingRemainingTime(),
                ];
            });
    }

    public function getPackageRejectReasons()
    {
        return PackageRejectionReason::all();
    }

    public function getEarningStatistics(Captain $captain, Carbon $from, Carbon $to)
    {
        return Order::query()
            ->select(DB::raw('count(*) as attended_orders'), DB::raw('avg(captain_commissions.commission) as avg_commission'), DB::raw('sum(captain_commissions.commission) as total_commission'), DB::raw('sum(captain_commissions.settled_amount) as total_payed_commission'))
            ->has('captainCommission')
            ->leftJoin('captain_commissions', 'captain_commissions.order_id', 'orders.id')
            ->where('orders.captain_id', $captain->id)
            ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED])
            ->whereBetween('orders.delivery_date', [$from, $to])
            ->first();
    }

    public function getEarningStatisticsList(Captain $captain, Carbon $from, Carbon $to, int $perPage): ?LengthAwarePaginator
    {
        return Order::query()
            ->with('captain.user', 'client.user', 'shop', 'progress', 'payment', 'shopPayment', 'captainCommission')
            ->has('captainCommission')
            ->where('captain_id', $captain->id)
            ->orderBy('delivery_date', 'desc')
            ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED])
            ->whereBetween('orders.delivery_date', [$from, $to])
            ->paginate($perPage);
    }

    public function getCommissionTransactionList(Captain $captain, int $perPage): ?LengthAwarePaginator
    {
        return CaptainCommissionPayment::query()
            ->with('commission', 'captain', 'settledBy', 'paymentMode', 'commission.attachments')
            ->has('settledBy')
            ->whereHas('captain', function ($query) use ($captain) {
                $query->where('id', '=', $captain->id);
            })
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }

    public function updateOrder(Order $order, array $data): Order
    {
        //return tap($order->update($data));
        $order->update($data);
        return $order;
    }

    public function findPackageId(int $packageId)
    {
        return Package::with('orders', 'shop')->findOrFail($packageId);
    }

    public function updateLatestPackageDeliveryRequest(int $packageId, int $captainId)
    {
        $package_request = PackageDeliveryRequest::where([['package_id', '=', $packageId], ['captain_id', '=', $captainId]])
            ->latest()
            ->first();
        if ($package_request) {
            $package_request->attempted_at = now()->toDateTimeString();
            $package_request->save();
        } else {
            Log::info('update Latest Package Delivery Request failed', [
                'package_id' => $packageId,
                'captainId' => $captainId,
                'time' => now()->toDateTimeString(),
            ]);
        }

        return true;
    }

    public function updateOrderStatus($package, $captain)
    {
        $orders = $package->ordersByPriority();

        $updateOrders = [];

        foreach ($orders as $key => $order) {
            $updated = $this->updateOrder($order->order, [
                'captain_id' => $captain->id,
                'status_id' => OrderStatus::ACCEPT,
                'created_by' => 0,
            ]);

            $updateOrders[] = $updated;

            OrderStatusLog::log(OrderStatus::ACCEPT, $captain->id, $order->order->id, null, null, null, $captain->user->id);
        }

        return $updateOrders;
    }

    public function updatePackage(Package $package, array $data)
    {
        return $package->update($data);
    }

    public function getPackageDeliveryRequest(int $packageId, int $captainId)
    {
        return PackageDeliveryRequest::where([['package_id', '=', $packageId], ['captain_id', '=', $captainId]])
            ->latest()
            ->where('declined_at', null)
            ->get();
    }

    public function getDirectOrders(int $packageId)
    {
        return Package::with('directOrders')->whereKey($packageId)->first();
    }

    public function getRejectionReasonText(int $reasonId)
    {
        if (!$reasonId) {
            return null;
        }
        return PackageRejectionReason::find($reasonId)?->reason;
    }

    public function markOrderDeclined(array $packageIds, ?int $reasonId)
    {
        return PackageDeliveryRequest::whereIn('id', $packageIds)->update([
            'declined_at' => now(),
            'rejection_reason_id' => $reasonId,
        ]);
    }

    public function getCaptainOrders(int $captainId, ?int $status): Collection
    {
        $orders = Order::select('orders.*')
            ->doesntHave('pickedOrders')
            ->with('client.user', 'latestAddress', 'captain', 'shopPayment', 'zone', 'shop', 'openTicket', 'items.customizations')
            ->leftJoin('package_orders', 'order_id', 'orders.id')
            ->leftJoin('packages', 'package_orders.package_id', 'packages.id')
            ->where([['packages.captain_id', $captainId], ['orders.captain_id', $captainId]])
            ->whereNotIn('status_id', [OrderStatus::NOT_ASSIGNED, OrderStatus::DELIVERED, OrderStatus::CANCEL, OrderStatus::FORYOU_RETURN_ACCEPTED, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::REQUEST_FOR_CANCEL, OrderStatus::CLIENT_RETURN_DECLINE, OrderStatus::WAITING_FOR_ACCEPTING, OrderStatus::WAITING_TIME_OUT])
            ->orderBy('package_orders.priority', 'asc')
            ->orderBy('packages.id', 'asc')
            ->when($status, function ($query, $status) {
                $query->where('status_id', $status);
            });

        if (request()->has('data.status') && in_array($status, [OrderStatus::ACCEPT, OrderStatus::REQUEST_FOR_CANCEL, OrderStatus::START_RIDE, OrderStatus::REACHED_SHOP])) {
            $orders = $orders->where('orders.status_id', $status);
        }

        return $orders->get()->unique('id')->values();
    }
}
