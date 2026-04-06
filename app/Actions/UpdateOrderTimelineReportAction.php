<?php

namespace App\Actions;

use App\OrderReport;
use App\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class UpdateOrderTimelineReportAction
{

    /**
     * Handle the captain report update.
     *
     * @param array $orderData
     * @return void
     */
    public function execute(array $orderData)
    {
        $query = DB::table('orders')
            ->select(
                'orders.id',
                'orders.status_id as status_id',
                'orders.shopname as shop_id',
                'orders.zone_id as zone_id',
                'orders.region_id as region_id',
                DB::raw("(
                SELECT users.id
                FROM order_logs
                LEFT JOIN users ON users.id = order_logs.created_by
                WHERE order_logs.status_id = " . OrderStatus::ACCEPT . "
                  AND order_logs.order_id = orders.id
                ORDER BY order_logs.id DESC LIMIT 1
            ) as assigned_by_id"),
                DB::raw("(
                SELECT users.id
                FROM order_logs
                LEFT JOIN users on users.id = order_logs.created_by
                WHERE order_logs.status_id = " . OrderStatus::NEW_ORDER . " AND order_logs.order_id = orders.id
                ORDER BY order_logs.id desc LIMIT 1
            ) as created_by_id"),

            )
            ->where("orders.id", $orderData['id'])
            ->whereIn('orders.status_id', [
                OrderStatus::DELIVERED,
                OrderStatus::CANCEL,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
                OrderStatus::CANCEL_REQUEST_ACCEPTED,
            ])
            ->orderBy('orders.created_at', 'asc')
            ->first();

        $timeLineData = $this->timeLine($orderData['id']);

        $statuses = $timeLineData['statuses'];
        $finalStatus = $timeLineData['final_status'];

        // Calculate time differences and insert into OrderReports
        $orderAcceptedAt = isset($statuses[OrderStatus::ACCEPT])
        ? Carbon::parse($statuses[OrderStatus::ACCEPT]->first()->created_at)
        : null;
        $startRideAt = isset($statuses[OrderStatus::START_RIDE])
        ? Carbon::parse($statuses[OrderStatus::START_RIDE]->first()->created_at)
        : null;

        $reachedShopAt = isset($statuses[OrderStatus::REACHED_SHOP])
        ? Carbon::parse($statuses[OrderStatus::REACHED_SHOP]->first()->created_at)
        : null;

        $orderPickedAt = isset($statuses[OrderStatus::PICKED])
        ? Carbon::parse($statuses[OrderStatus::PICKED]->first()->created_at)
        : null;

        $shippedAt = isset($statuses[OrderStatus::SHIPPED])
        ? Carbon::parse($statuses[OrderStatus::SHIPPED]->first()->created_at)
        : null;

        $reachedDestAt = isset($statuses[OrderStatus::REACHED_DESTINATION])
        ? Carbon::parse($statuses[OrderStatus::REACHED_DESTINATION]->first()->created_at)
        : null;

        $finalStatusAt = $finalStatus->created_at ?? null;

        $tickets = DB::table('tickets')
            ->selectRaw('GROUP_CONCAT(subject) as subjects, type')
            ->where('order_id', $orderData['id'])
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $cancellationReason = DB::table('order_logs')
            ->selectRaw('COALESCE(cancellation_reasons.reason, order_logs.note) as reason')
            ->leftJoin('cancellation_reasons', 'cancellation_reasons.id', '=', 'order_logs.reason_id')
            ->where('order_id', $orderData['id'])
            ->where('status_id', OrderStatus::CANCEL)
            ->orderByDesc('order_logs.id')
            ->value('reason');

        //* Update or create the CaptainReport with the new values
        OrderReport::updateOrCreate(
            ['order_id' => $orderData['id']],
            [
                'assigned_by' => $query->assigned_by_id ?? null,
                'created_by' => $query->created_by_id ?? null,
                'status_id' => $query->status_id ?? null,
                'shop_id' => $query->shop_id ?? null,
                'region_id' => $query->region_id ?? null,
                'zone_id' => $query->zone_id ?? null,
                'order_accepted_at' => $orderAcceptedAt ?? null,
                'start_ride_at' => $startRideAt ?? null,
                'reached_shop_at' => $reachedShopAt ?? null,
                'order_picked_at' => $orderPickedAt ?? null,
                'shipped_at' => $shippedAt ?? null,
                'reached_dest_at' => $reachedDestAt ?? null,
                'final_status_at' => $finalStatusAt ?? null,
                'ticket' => $tickets[1]->subjects ?? null,
                'pending_ticket' => $tickets[2]->subjects ?? null,
                'client_ticket' => $tickets[3]->subjects ?? null,
                'cancellation_reason' => $cancellationReason ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

    }

    public function timeLine($orderId)
    {
        $statuses = [
            OrderStatus::NEW_ORDER,
            OrderStatus::ACCEPT,
            OrderStatus::START_RIDE,
            OrderStatus::REACHED_SHOP,
            OrderStatus::PICKED,
            OrderStatus::SHIPPED,
            OrderStatus::REACHED_DESTINATION,
        ];

        $timeLine = DB::table('order_logs')
            ->select('order_logs.order_id', 'order_logs.status_id', 'order_logs.created_at')
            ->where('order_logs.order_id', $orderId)
            ->whereIn('order_logs.status_id', $statuses)
            ->orderBy('order_logs.id', 'asc')
            ->get()
            ->groupBy('status_id');

        $finalStatus = DB::table('order_logs')
            ->select('order_logs.order_id', 'order_logs.created_at')
            ->join('orders', 'orders.id', '=', 'order_logs.order_id')
            ->whereColumn('orders.status_id', 'order_logs.status_id')
            ->where('order_logs.order_id', $orderId)
            ->orderBy('order_logs.id', 'desc')
            ->first();

        return [
            'statuses' => $timeLine,
            'final_status' => $finalStatus,
        ];
    }

}
