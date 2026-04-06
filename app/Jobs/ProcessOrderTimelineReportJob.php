<?php

namespace App\Jobs;

use App\OrderReport;
use App\OrderStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProcessOrderTimelineReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */

    public $timeout = 86400; //24 hours

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $orders = DB::table('orders')
            ->select(
                'orders.id',
                'orders.status_id as status_id',
                'orders.shopname as shop_id',
                'orders.zone_id as zone_id',
                'orders.region_id as region_id',
                'orders.created_at as created_at',
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
                LEFT JOIN users ON users.id = order_logs.created_by
                WHERE order_logs.status_id = " . OrderStatus::NEW_ORDER . "
                  AND order_logs.order_id = orders.id
                ORDER BY order_logs.id DESC LIMIT 1
            ) as created_by_id")
            )
            ->whereIn('orders.status_id', [
                OrderStatus::DELIVERED,
                OrderStatus::CANCEL,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
                OrderStatus::CANCEL_REQUEST_ACCEPTED,
            ])
            ->orderBy('orders.created_at', 'asc')
        // ->whereBetween('orders.delivery_date', [
        //     Carbon::parse('2024-01-01 00:00:00'),
        //     Carbon::parse('2024-12-01 24:00:00'),
        // ])

        // ->where('orders.id', 951757)
        // ->where("orders.client_id", 1)
        // ->where("orders.captain_id", 12)
            // ->limit(1)
            // ->get();
        // foreach ($orders as $order) {
        //     // Log::info('Processing order:', ['order_details' => $order->assigned_by_id]);
        //     $this->processOrder( $order);
        //     // $this->orderReportProcessingService->processOrder($order);
        //     // $this->processOrder($orders);
        // }
        ->chunk(1000, function ($orders) {
            foreach ($orders as $order) {
                $this->processOrder($order);
            }
        });
    }


    private function processOrder($orderData): void
    {

        $timeLineData = $this->timeLine($orderData->id);

        $statuses = $timeLineData['statuses'];
        $finalStatus = $timeLineData['final_status'];

        $orderAcceptedAt = $this->parseStatusTime($statuses, OrderStatus::ACCEPT);
        $startRideAt = $this->parseStatusTime($statuses, OrderStatus::START_RIDE);
        $reachedShopAt = $this->parseStatusTime($statuses, OrderStatus::REACHED_SHOP);
        $orderPickedAt = $this->parseStatusTime($statuses, OrderStatus::PICKED);
        $shippedAt = $this->parseStatusTime($statuses, OrderStatus::SHIPPED);
        $reachedDestAt = $this->parseStatusTime($statuses, OrderStatus::REACHED_DESTINATION);
        $finalStatusAt = $finalStatus->created_at ?? null;

        $tickets = DB::table('tickets')
            ->selectRaw('GROUP_CONCAT(subject) as subjects, type')
            ->where('order_id', $orderData->id)
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $cancellationReason = DB::table('order_logs')
            ->selectRaw('COALESCE(cancellation_reasons.reason, order_logs.note) as reason')
            ->leftJoin('cancellation_reasons', 'cancellation_reasons.id', '=', 'order_logs.reason_id')
            ->where('order_id', $orderData->id)
            ->where('status_id', OrderStatus::CANCEL)
            ->orderByDesc('order_logs.id')
            ->value('reason');

        OrderReport::updateOrCreate(
            ['order_id' => $orderData->id],
            [
                'assigned_by' => $orderData->assigned_by_id ?? null,
                'status_id' => $orderData->status_id ?? null,
                'shop_id' => $orderData->shop_id ?? null,
                'region_id' => $orderData->region_id ?? null,
                'zone_id' => $orderData->zone_id ?? null,
                'created_by' => $orderData->created_by_id ?? null,
                'order_accepted_at' => $orderAcceptedAt,
                'start_ride_at' => $startRideAt,
                'reached_shop_at' => $reachedShopAt,
                'order_picked_at' => $orderPickedAt,
                'shipped_at' => $shippedAt,
                'reached_dest_at' => $reachedDestAt,
                'final_status_at' => $finalStatusAt,
                'ticket' => $tickets[1]->subjects ?? null,
                'pending_ticket' => $tickets[2]->subjects ?? null,
                'client_ticket' => $tickets[3]->subjects ?? null,
                'order_created_at' => $orderData->created_at ?? null,
                'cancellation_reason' => $cancellationReason ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function parseStatusTime($statuses, $statusId): ?string
    {
        return isset($statuses[$statusId])
        ? Carbon::parse($statuses[$statusId]->first()->created_at)
        : null;
    }

    private function timeLine($orderId): array
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
