<?php

namespace App\Services\PublicApi;

use App\CancellationReason;
use App\Captain;
use App\Client;
use App\ClientShopTimeSlot;
use App\Events\OrderStatusChanged;
use App\Filter\OrderFilter;
use App\Interfaces\OrderInterface;
use App\Interfaces\TicketInterface;
use Illuminate\Support\Facades\Log;
use App\Order;
use App\OrderPendingReason;
use App\OrderStatus;
use App\Services\OrderStatusLog;
use App\Services\Search;
use App\User;
use App\Vat;
use Facades\App\Services\OrderStatusLog as ServicesOrderStatusLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class OrderService
{
    public function __construct(protected readonly OrderInterface $orderInterface, protected readonly TicketInterface $ticketInterface) {}

    public function createOrder(array $data)
    {
        DB::connection('mysql::write')->beginTransaction();

        try {
            foreach ($data['client_order_id'] as $i => $orderId) {
                $orderData = $this->prepareOrderData($data, $i);
                $order = $this->orderInterface->createOrder($orderData);
                $log = new OrderStatusLog();
                $log->log(OrderStatus::NEW_ORDER, null, $order->id);
            }
            DB::commit();
            return true;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Order creation failed', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            throw new \Exception('Could not create orders: ' . $e->getMessage());
        }
    }

    private function prepareOrderData(array $data, int $i): array
    {
        $isFast = $data['delivery_type'][$i] == '1';

        $slot = $isFast ? null : ClientShopTimeSlot::find($data['delivery_time'][$i]);
        if (!$isFast && !$slot) {
            throw new \Exception('Invalid delivery time slot');
        }

        $deliveryTimeText = $isFast
            ? 'Delivery within 1 hour on ' . now()->toDateTimeString()
            : 'Delivery from (' . $slot->name . ')';

             $dispatchAt = $isFast
        ? now()->toDateTimeString()
        : Carbon::parse($data['delivery_date'][$i])
            ->setTimeFromTimeString($slot->start_time->format('H:i:s'))
            ->subMinutes($slot->close_before ?? 15)
            ->toDateTimeString();

        // $dispatchAt = $isFast
        //     ? now()->toDateTimeString()
        //     : Carbon::parse(
        //         $data['delivery_date'][$i] . ' ' .
        //         $slot->start_time->subMinutes($slot->close_before ?? 15)
        // )->toDateTimeString();

        return [
            'client_order_id' => $data['client_order_id'][$i],
            'code' => $data['client_order_id'][$i],
            'client_id' => $data['client_id'],
            'customer_number' => ltrim($data['customer_number'][$i], '0'),
            'delivery_payment_mode' => $data['delivery_payment_mode'][$i],
            'delivery_type' => $isFast ? 'Fast' : 'Scheduled',
            'amount' => $data['amount'][$i] ?? 0,
            'delivery_charge' => 0,
            'delivery_time' => $deliveryTimeText,
            'location' => $data['address'][$i] ?? '',
            'zone_id' => null,
            'region_id' => null,
            'status_id' => OrderStatus::NEW_ORDER,
            'delivery_date' => isset($data['delivery_date'][$i]) ? Carbon::parse($data['delivery_date'][$i])->toDateString() : now()->toDateString(),
            'scheduled_delivery_time_slot_id' => $data['delivery_time'][$i],
            'dispatch_at' => $dispatchAt,
            'shopname' => $data['shopname'],
            'vat_rate' => (float) $this->getVatRate($data['client_id']),
        ];
    }

    public function getVatRate()
    {
        return Vat::where('status', 'active')->orderBy('id', 'desc')->first()?->rate ?? 0;
    }

    public function getZone($address)
    {
        $search = new Search();
        return $search->getZone($address);
    }

    public function addNotesToOrder(Order $order, string $note, User $user)
    {
        return $this->orderInterface->addNotesToOrder($order, $note, $user);
    }

    public function getOnlineCaptains(array $filters, int $perPage)
    {
        $captains = $this->orderInterface->getOnlineCaptains($filters, $perPage);

        $captains->getCollection()->transform(function ($captain) {
            return [
                'id' => $captain->id,
                'name' => $captain->user->name,
                'vehicle_type' => $captain->vehicle->type ?? null,
                'delivered_orders_count' => $captain->delivered_orders_count,
                'is_online' => $captain->isOnline(),
            ];
        });

        return $captains;
    }

    public function changeOrderStatus(Order $order, Request $request, User $user)
    {
        $statusId = $request->input('status_id');

        $statusData = $this->resolveReasonId($order, $statusId, $request);
        $statusId = $statusData['status_id'];
        $reasonId = $statusData['reason_id'];

        $previous_log = $order->logsExecpt()->latest()->first();
        $will_redispatch = $previous_log && in_array($previous_log->status_id, OrderStatus::FINISHED) && !in_array($statusId, OrderStatus::FINISHED);
        $status = OrderStatus::find($statusId);
        $content = 'Order No ' . $order->client_order_id . ' Status changed from ' . $order->progress->name . ' to ' . $status->name;
        $log = new OrderStatusLog();
        $log->logs('Order', $content, $user->id);

        $this->ticketInterface->handleOrderCloseTicket($order, $statusId, $user);
        $this->orderInterface->updateOrderStatus($order, $statusId, $user);
        if ($this->orderInterface->handleReschedule($order, $statusId, $request, $reasonId)) {
            return [
                'message' => 'Order status updated and rescheduled successfully',
            ];
        }

        $note = $request->get('note', null);

        if ($will_redispatch && $request->get('redispatch_reason')) {
            $note .= ($note ? ' | ' : '') . 'Redispatching Reason: ' . $request->get('redispatch_reason');
        }

        ServicesOrderStatusLog::log($statusId, null, $order->id, $reasonId, $note ?: null, $request->get('canceled_by', null));
        OrderStatusChanged::dispatch($order);

        return [
            'message' => 'Order status updated successfully',
        ];
    }

    private function resolveReasonId(Order $order, ?int $statusId, Request $request): array
    {
        $reasonId = null;

        // Handle client cancellation reasons
        if ($request->boolean('from_client') && in_array($statusId, [OrderStatus::CANCEL, OrderStatus::REQUEST_FOR_CANCEL]) && $order->clientCancelable()) {
            $note = $request->input('note');
            $reason = CancellationReason::where('reason', $note)->first();
            $reasonId = $reason?->id;

            // Force REQUEST_FOR_CANCEL if caused by 4U
            if ($reason?->is_caused_by_4u) {
                $statusId = OrderStatus::REQUEST_FOR_CANCEL;
            }
        }

        // Handle pending reason
        if ($statusId === OrderStatus::PENDING) {
            $pendingReason = $request->input('pending_reason');
            $reasonId = $pendingReason == OrderPendingReason::OTHERS ? null : $pendingReason;
        }

        return [
            'status_id' => $statusId,
            'reason_id' => $reasonId,
        ];
    }

    public function checkBulkAssignOrders(array $orderIds, int $captainId): array
    {
        $orders = $this->orderInterface->getOrdersWithCaptain($orderIds);

        $captain = Captain::findOrFail($captainId);

        if (!$captain->isOnline()) {
            return [
                'message' => "{$captain->firstname} is offline now. Do you want to confirm this action?",
                'showConfirm' => true,
            ];
        }

        $newOrdersCount = $orders->where('status_id', OrderStatus::NEW_ORDER)->count();

        // Not all orders are NEW
        if ($orders->count() !== $newOrdersCount) {
            $nonNewTotal = $orders->count() - $newOrdersCount;
            // exactly one non-new order
            if ($nonNewTotal === 1) {
                $assignedOrder = $orders->firstWhere('captain_id', '!=', null);
                if ($assignedOrder) {
                    return [
                        'message' => "The order is already with {$assignedOrder->captain->firstname}, do you confirm to assign to {$captain->firstname}?",
                        'showConfirm' => true,
                    ];
                }
            }
        }

        return [
            'message' => 'All orders can be assigned to the selected captain.',
            'showConfirm' => false,
        ];
    }

    public function getScheduledOrders(OrderFilter $filter, $perPage)
    {
        return $this->orderInterface->getScheduledOrders($filter, $perPage);
    }

    public function getConsolidatedOrders(array $request, int $perPage, User $user)
    {
        return $this->orderInterface->getConsolidatedOrders($request, $perPage, $user);
    }

    public function getSingleConsolidatedOrder(int $shopId, array $filters, int $perPage)
    {
        return $this->orderInterface->getSingleConsolidatedOrder($shopId, $filters, $perPage);
    }
}
