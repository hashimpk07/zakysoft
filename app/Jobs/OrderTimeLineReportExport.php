<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\Order;
use App\OrderLog;
use App\OrderStatus;
use App\Ticket;
use App\User;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class OrderTimeLineReportExport extends QueueExport
{
    protected int $chunk = 50000;

    protected string $file_name = 'order-performance';

    public function data(): array
    {
        $data = [];
        $orders = $this->getOrders();
        $order_time_lines = $this->timeLine($orders);
        foreach ($orders as $order) {  
            $times = [];
            $order_created_at = now()->parse($order->created_at);
            $time_lines = $order_time_lines[$order->id] ?? collect();
            for ($i = 0; $i < 7; $i++) {
                $status = [OrderStatus::NEW_ORDER, OrderStatus::ACCEPT, OrderStatus::START_RIDE, OrderStatus::REACHED_SHOP, OrderStatus::PICKED, OrderStatus::SHIPPED, OrderStatus::REACHED_DESTINATION];
                $time_line = $time_lines->where('status_id', $status[$i])->first();
                $times[] = (isset($time_line->created_at) && $time_line->created_at) ? 
                        (
                            $order_created_at->isSameDay(now()->parse($time_line->created_at)) ? 
                                now()->parse($time_line->created_at)->format('h:i:s a') : 
                                now()->parse($time_line->created_at)->format('Y-m-d h:i:s a')
                        ) : 'NA';
                if($i == 1) {
                    $times[] = $time_line ? secondsToTime($order_created_at->diffInSeconds(now()->parse($time_line->created_at))) : "NA";
                    $acceptance_time = $time_line ? now()->parse($time_line->created_at) : null;
                }
                if($i == 3) {
                    $times[] = $acceptance_time && isset($time_line->created_at) ? secondsToTime($acceptance_time->diffInSeconds(now()->parse($time_line->created_at))) : "NA";
                    $reached_shop_time = $time_line ? now()->parse($time_line->created_at) : null;
                }
                if($i == 4) {
                    $times[] = $reached_shop_time && isset($time_line->created_at) ? secondsToTime($reached_shop_time->diffInSeconds(now()->parse($time_line->created_at))) : "NA";
                    $picked_time = $time_line ? now()->parse($time_line->created_at) : null;
                }
            }
            $final_status = $time_lines->last();
            $times[] = isset($final_status->created_at) && $final_status->created_at ? ($order_created_at->isSameDay(now()->parse($final_status->created_at)) ? now()->parse($final_status->created_at)->format('h:i:s a') : now()->parse($final_status->created_at)->format('d-m-Y h:i:s a')) : 'NA';
            $times[] = $picked_time && isset($final_status->created_at) ? secondsToTime($picked_time->diffInSeconds(now()->parse($final_status->created_at))) : "NA";
            
            $data[] = [
                $order->id,
                $order->created_by,
                $order->client_order_id,
                $order->client_name,
                $order->shop_name,
                $order->captain_name,
                $order->assigned_by ? ($order->assigned_by == $order->captain_name ? "SYSTEM": $order->assigned_by) : '',
                $order->status_name,
                $order_created_at->format('Y-m-d'),
                ...$times,
                $order->shop_to_delivery_km,
                $order->processing_time,
                $order->ticket,
                $order->pending_ticket,
                $order->client_ticket,
                $order->cancellation_reason
            ];
        }

        return $data;   
    }


    public function headers(): array
    {
        return ['Order ID', 'Order Created By', 'Client Order Id', 'Client Name', 'Shop Name', 'Captain', 'Assigned by', 'Order Status', 'Date', 'New Order (Created At)', 'Order Accepted', 'Acceptance time', 'Start Ride', 'Reached shop', 'Reached Time', 'Order Picked', 'Picked Time', 'Shipped', 'Reached Destination', 'Final Status Time', 'Pickup to Delivery Time', 'Distance B/W', 'Process time(in Minutes)', 'Ticket', 'Pending Ticket', 'Client Ticket', 'Cancellation Reason'];
    }

    public function getOrders()
    {
        $request = $this->export->filters;

        $client = isset($request['client']) ? $request['client'] : NULL;
        $shop = isset($request['shop']) ? $request['shop'] : NULL;
        $start_date = isset($request['from_date']) ? $request['from_date'] : now()->startOfMonth()->format('Y-m-d');
        $end_date =isset( $request['to_date'] ) ? $request['to_date'] : now()->format('Y-m-d') ;
        $term = isset($request['client_order_id']) ? $request['client_order_id'] :'';
    
        $orders =  Order::query()
            ->select(
                'orders.id', 
                'orders.client_order_id',
                DB::raw("(
                    SELECT users.name 
                    FROM order_logs 
                    LEFT JOIN users on users.id = order_logs.created_by
                    WHERE order_logs.status_id = ". OrderStatus::NEW_ORDER ." AND order_logs.order_id = orders.id 
                    ORDER BY order_logs.id desc LIMIT 1
                ) as created_by"), 
                'client_user.name as client_name', 
                'client_shops.name as shop_name',
                'orders.created_at',
                DB::raw("captain_user.name as captain_name"),
                DB::raw('TIMESTAMPDIFF(MINUTE,
                    (SELECT created_at FROM order_logs WHERE order_logs.status_id = '. OrderStatus::NEW_ORDER . ' AND order_logs.order_id = orders.id ORDER BY id desc LIMIT 1),
                    (IFNULL((SELECT created_at FROM order_logs WHERE order_logs.status_id = '. OrderStatus::DELIVERED . ' AND order_logs.order_id = orders.id ORDER BY id desc LIMIT 1), now()))
                ) as processing_time'),
                DB::raw("(
                        SELECT users.name 
                        FROM order_logs 
                        LEFT JOIN users on users.id = order_logs.created_by
                        WHERE order_logs.status_id = ". OrderStatus::ACCEPT ." AND order_logs.order_id = orders.id 
                        ORDER BY order_logs.id desc LIMIT 1
                    ) as assigned_by"
                ),
                'order_statuses.name as status_name',
                'orders.status_id',
                'orders.shop_to_delivery_km'
            )
        ->addSelect([
            'ticket' => Ticket::query()
                ->select(DB::raw('GROUP_CONCAT(subject)'))
                ->whereColumn('orders.id', 'tickets.order_id')
                ->where('type', 1),
            'pending_ticket' => Ticket::query()
                ->select(DB::raw('GROUP_CONCAT(subject)'))
                ->whereColumn('orders.id', 'tickets.order_id')
                ->where('type', 2),
            'client_ticket' => Ticket::query()
                ->select(DB::raw('GROUP_CONCAT(subject)'))
                ->whereColumn('orders.id', 'tickets.order_id')
                ->where('type', 3),
            'cancellation_reason' => OrderLog::query()
                ->select(DB::raw('COALESCE(cancellation_reasons.reason, order_logs.note)'))
                ->leftJoin('cancellation_reasons', 'cancellation_reasons.id', '=', 'order_logs.reason_id')
                ->whereColumn('orders.id', 'order_logs.order_id')
                ->where('status_id', OrderStatus::CANCEL)
                ->orderBy('order_logs.id', 'desc')
                ->limit(1)
        ])
        ->leftJoin('clients', 'orders.client_id', '=', 'clients.id')
        ->leftJoin('users as client_user', 'clients.user_id', '=', 'client_user.id')
        ->leftJoin('client_shops', 'orders.shopname', '=', 'client_shops.id')
        ->leftJoin('captains', 'orders.captain_id', '=', 'captains.id')
        ->leftJoin('users as captain_user', 'captain_user.id', '=', 'captains.user_id')
        ->leftJoin('order_statuses', 'orders.status_id', '=', 'order_statuses.id')
        ->when($client, function ($query, $client) {
            return $query->where('orders.client_id', $client);
        })
        ->when($shop, function ($query, $shop) {
            return $query->where('orders.shopname', $shop);
        })
        ->when($start_date, function ($query, $start_date) {
            $start_date = now()->parse(now()->parse($start_date)->format('d-m-Y') . ' ' . (request()->get('order_time_from') ?? '00:00'))->format('Y-m-d H:i:s');
            return $query->where('orders.created_at', '>=', $start_date);
        })
        ->when($end_date, function ($query, $end_date) {
            $end_date = now()->parse(now()->parse($end_date)->format('d-m-Y') . ' ' . (request()->get('order_time_to') ?? '23:59'))->format('Y-m-d H:i:s');
            return $query->where('orders.created_at', '<=', $end_date);
        })
        ->when($term, function ($query, $term) {
            return $query->where('orders.client_order_id', $term);
        })            
        ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED])
        ->orderBy('orders.created_at', 'asc')
        ->belongsToUser(User::find($this->export->created_by))
        ->orderBy('id', 'desc')
        ->limit($this->chunk)
        ->offset($this->chunk * $this->export->page_done ?? 0)
        ->get();
        
        return $orders;
    }

    public function count(): int
    {
        $request = $this->export->filters;

        $client = isset($request['client']) ? $request['client'] : NULL;
        $shop = isset($request['shop']) ? $request['shop'] : NULL;
        $start_date = isset($request['from_date']) ? $request['from_date'] : now()->startOfMonth()->format('Y-m-d');
        $end_date =isset( $request['to_date'] ) ? $request['to_date'] : now()->format('Y-m-d') ;
        $term = isset($request['client_order_id']) ? $request['client_order_id'] :'';

        return Order::query()
        ->when($client, function ($query, $client) {
            return $query->where('orders.client_id', $client);
        })
        ->when($shop, function ($query, $shop) {
            return $query->where('orders.shopname', $shop);
        })
        ->when($start_date, function ($query, $start_date) {
            $start_date = now()->parse(now()->parse($start_date)->format('d-m-Y') . ' ' . (request()->get('order_time_from') ?? '00:00'))->format('Y-m-d H:i:s');
            return $query->where('orders.created_at', '>=', $start_date);
        })
        ->when($end_date, function ($query, $end_date) {
            $end_date = now()->parse(now()->parse($end_date)->format('d-m-Y') . ' ' . (request()->get('order_time_to') ?? '23:59'))->format('Y-m-d H:i:s');
            return $query->where('orders.created_at', '<=', $end_date);
        })
        ->when($term, function ($query, $term) {
            return $query->where('orders.client_order_id', $term);
        })            
        ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED])
        ->belongsToUser(User::find($this->export->created_by))
        ->count() ?? 0;
    }

    public function timeLine($orders)
    {
        $order_ids = $orders->pluck('id');
        $time_lines = [];
        $time_lines = $order_ids->map(function($order_ids) {
            $time_line = DB::table('order_logs')
                ->select('order_logs.order_id', DB::raw('max(order_logs.id) as id'), DB::raw('max(order_logs.created_at) as created_at') , 'order_logs.status_id', 'order_statuses.name')
                ->leftJoin('order_statuses', 'order_statuses.id', '=', 'order_logs.status_id')
                ->where('order_logs.order_id', $order_ids)
                ->whereIn('order_logs.status_id', [OrderStatus::NEW_ORDER, OrderStatus::ACCEPT, OrderStatus::START_RIDE, OrderStatus::REACHED_SHOP, OrderStatus::PICKED, OrderStatus::SHIPPED, OrderStatus::REACHED_DESTINATION])
                ->groupBy('order_logs.order_id', 'order_statuses.name', 'order_logs.status_id')
                ->get();

            $final_status = DB::table('order_logs')
                ->select('order_logs.order_id', DB::raw('max(order_logs.id) as id'), DB::raw('max(order_logs.created_at) as created_at') , 'order_logs.status_id', 'order_statuses.name')
                ->leftJoin('order_statuses', 'order_statuses.id', '=', 'order_logs.status_id')
                ->leftJoin('orders', 'orders.id', '=', 'order_logs.order_id')
                ->whereColumn('orders.status_id', 'order_logs.status_id')
                ->where('order_logs.order_id', $order_ids)
                ->groupBy('order_logs.order_id', 'order_statuses.name', 'order_logs.status_id')
                ->first();
            
            $time_line->push($final_status);

            return $time_line;
        });

        return $time_lines->flatten()->groupBy('order_id');
    }
}
