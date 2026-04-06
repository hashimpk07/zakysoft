<?php
namespace App\Graph;

use App\OrderLog;
use App\OrderReport;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;

class ClientOrderCancellationReasons implements Graph
{
    public function data($request = null)
    {
        // $startDate = request()->has('from_date') ? now()->parse(request()->get('from_date'))->startOfDay() : now()->startOfDay();
        // $endDate   = request()->has('to_date') ? now()->parse(request()->get('to_date'))->endOfDay() : now()->endOfDay();
        $fromDate = $request->get('from_date', now()->format('Y-m-d'));
        $toDate = $request->get('to_date', now()->format('Y-m-d'));

        //This is for showing the date range in the dashboard
        $fromDateTime = \Carbon\Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDateTime =  \Carbon\Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        //This is for filtering the orders based on the date range
        $startDate = $fromDateTime->format('Y-m-d H:i:s');
        $endDate =  $toDateTime->format('Y-m-d H:i:s');

        $client    = request()->get('client');

        $colors = [
            '#36a2eb',
            '#ff6384',
            '#ff9f40',
            '#ffcd56',
            '#14b8a6',
            '#6b7280',
            '#1f6f70',
            '#b5d1af',
            '#5f4c60',
            '#97a7c4',
            '#F5921B',
            '#e1a692',
            '#54bebe',
            '#dedad2',
            '#badbdb',
            '#e8daff',
            '#ff8389',
            '#3ddbd9',
            '#20B2AA',
            '#ADD8E6',
            '#90EE90',
            '#FF6347',
        ];
        $values = [];
        $labels = [];

        $data = OrderReport::select(DB::raw('count(*) as count'))
            ->addSelect([
                'reason' => OrderLog::select(DB::raw(
                    'CASE
                WHEN REGEXP_LIKE(
                    COALESCE(
                        REGEXP_REPLACE(cancellation_reasons.reason, "Order No [0-9]+", "Order No XXX"),
                        REGEXP_REPLACE(order_logs.note, "Order No [0-9]+", "Order No XXX"),
                        CASE
                            WHEN order_pending_reasons.reason IS NULL AND order_reports.pending_ticket IS NOT NULL
                            THEN "Others"
                            ELSE order_reports.pending_ticket
                        END,
                        "Not Specified"
                    ),
                    "[ء-ي]"
                ) THEN "Others"
                ELSE COALESCE(
                    REGEXP_REPLACE(cancellation_reasons.reason, "Order No [0-9]+", "Order No XXX"),
                    REGEXP_REPLACE(order_logs.note, "Order No [0-9]+", "Order No XXX"),
                    CASE
                        WHEN order_pending_reasons.reason IS NULL AND order_reports.pending_ticket IS NOT NULL
                        THEN "Others"
                        ELSE order_reports.pending_ticket
                    END,
                    "Not Specified"
                )
            END as reason'
                ))
                    ->leftJoin('cancellation_reasons', 'cancellation_reasons.id', 'order_logs.reason_id')
                    ->leftJoin('order_pending_reasons', function ($join) {
                        $join->on('order_reports.pending_ticket', '=', 'order_pending_reasons.reason');
                    })
                    ->whereColumn('order_reports.order_id', 'order_logs.order_id')
                    ->whereIn('order_logs.status_id', [
                        OrderStatus::CANCEL,
                        OrderStatus::CLIENT_RETURN_ACCEPTED,
                        OrderStatus::CANCEL_REQUEST_ACCEPTED,
                        OrderStatus::FORYOU_RETURN_ACCEPTED,
                    ])
                    ->latest()
                    ->take(1),
            ])
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
            ->whereIn('order_reports.status_id', [
                OrderStatus::CANCEL,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
                OrderStatus::CANCEL_REQUEST_ACCEPTED,
                OrderStatus::FORYOU_RETURN_ACCEPTED,
            ])
            ->when($client, function ($query) use ($client) {
                $query->where('client_shops.client_id', $client);
            })
            ->groupBy('reason')
            ->excludeQuadrants('shop_region.quadrant_id')
            ->belongsToMe()
            ->get();

        // $data = OrderReport::select(DB::raw('count(*) as count'))
        //     ->addSelect([
        //         'reason' => OrderLog::select(DB::raw(
        //             'CASE
        //             WHEN REGEXP_LIKE(
        //                 COALESCE(
        //                     REGEXP_REPLACE(cancellation_reasons.reason, "Order No [0-9]+", "Order No XXX"),
        //                     REGEXP_REPLACE(order_logs.note, "Order No [0-9]+", "Order No XXX"),
        //                     order_reports.pending_ticket,
        //                     "Not Specified"
        //                 ),
        //                 "[ء-ي]"
        //             ) THEN "Others"
        //             ELSE COALESCE(
        //                 REGEXP_REPLACE(cancellation_reasons.reason, "Order No [0-9]+", "Order No XXX"),
        //                 REGEXP_REPLACE(order_logs.note, "Order No [0-9]+", "Order No XXX"),
        //                 order_reports.pending_ticket,
        //                 "Not Specified"
        //             )
        //         END as reason'
        //         ))
        //             ->leftJoin('cancellation_reasons', 'cancellation_reasons.id', 'order_logs.reason_id')
        //             ->whereColumn('order_reports.order_id', 'order_logs.order_id')
        //             ->whereIn('order_logs.status_id', [
        //                 OrderStatus::CANCEL,
        //                 OrderStatus::CLIENT_RETURN_ACCEPTED,
        //                 OrderStatus::CANCEL_REQUEST_ACCEPTED,
        //                 OrderStatus::FORYOU_RETURN_ACCEPTED,
        //             ])
        //             ->latest()
        //             ->take(1),
        //     ])

        //     ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
        //     ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
        //     ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
        //     ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
        //     ->whereIn('order_reports.status_id', [OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::FORYOU_RETURN_ACCEPTED])
        //     ->when($client, function ($query) use ($client) {
        //         $query
        //             ->where('client_shops.client_id', $client);
        //     })
        //     ->groupBy('reason')
        //     ->excludeQuadrants('shop_region.quadrant_id')
        //     ->belongsToMe()
        //     ->get();

        foreach ($data as $order) {
            if ($order->reason !== null) {
                $labels[] = $order->reason;
                $values[] = $order->count;
            }
        }

        return compact('colors', 'labels', 'values');
    }
}
