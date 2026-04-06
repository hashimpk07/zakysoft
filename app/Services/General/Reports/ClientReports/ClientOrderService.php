<?php

namespace App\Services\General\Reports\ClientReports;

use App\Interfaces\General\ClientReports\ClientOrderInterface;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Client;
use App\ClientShop;


final class ClientOrderService
{
   
    public function __construct(protected readonly ClientOrderInterface $interface) {}

    public function getClientOrderReport(array $data,int $perPage = 20 )
    {
        $businessStart = '06:00:00';
        $businessEnd = '05:59:59';
        $baseDate = now()->format('Y-m-d');
        $from_time = $data['order_time_from'] ?? $businessStart;
        $to_time = $data['order_time_to'] ?? $businessEnd;
        $from_date = Carbon::parse(($data['from_date'] ?? $baseDate) . " $from_time");
        $to_date = Carbon::parse(($data['to_date'] ?? $baseDate) . " $to_time");

        if ($to_time < $businessStart) {
            $to_date->addDay()->setTimeFromTimeString($to_time);
        }

        $client = $data['client'] ?? null;
        $shop = $data['shop'] ?? null;

        $baseQuery = DB::table('client_shops')
            ->select('users.name as client_name', 'client_shops.name as shop_name', 'client_shops.id as shop_id')
            ->leftJoin('clients', 'client_shops.client_id', '=', 'clients.id')
            ->leftJoin('users', 'clients.user_id', '=', 'users.id')
            ->when($client, fn($q) => $q->where('clients.id', $client))
            ->when($shop, fn($q) => $q->where('client_shops.id', $shop))
            ->whereExists(function ($query) use ($from_date, $to_date) {
                $query->select(DB::raw(1))
                    ->from('orders')
                    ->whereColumn('orders.shopname', 'client_shops.id')
                    ->whereBetween('orders.created_at', [$from_date, $to_date]);
            })
            ->where('client_shops.status', 'active')
            ->where(function ($query) {
                $query->where('client_shops.active_at', '<=', now()->format('Y-m-d') . ' 00:00:00')
                    ->orWhereNull('client_shops.active_at');
            })
            ->orderBy('client_id', 'asc');

        $clientShopsOrders = (clone $baseQuery)
            ->addSelect(
                DB::raw('(select count(*) FROM orders WHERE orders.shopname = client_shops.id AND orders.created_at >= "' . $from_date . '" AND orders.created_at <= "' . $to_date . '") as total_orders')
            )
            ->addSelect(
                DB::raw('(select count(*) FROM orders WHERE orders.shopname = client_shops.id AND orders.status_id = ' . \App\OrderStatus::DELIVERED . ' AND orders.created_at >= "' . $from_date . '" AND orders.created_at <= "' . $to_date . '") as delivered_orders')
            )
            ->addSelect(
                DB::raw('(select count(*) FROM orders as 4u_canceled_orders left join order_logs on 4u_canceled_orders.id = order_logs.order_id WHERE 4u_canceled_orders.shopname = client_shops.id AND order_logs.id IN (SELECT MAX(order_logs_last_attempt.id) from order_logs as order_logs_last_attempt where order_logs_last_attempt.order_id = 4u_canceled_orders.id) AND order_logs.canceled_by = "4u" AND 4u_canceled_orders.status_id in (' . \App\OrderStatus::CANCEL . ', ' . \App\OrderStatus::CLIENT_RETURN_ACCEPTED . ', ' . \App\OrderStatus::CANCEL_REQUEST_ACCEPTED . ') AND 4u_canceled_orders.created_at >= "' . $from_date . '" AND 4u_canceled_orders.created_at <= "' . $to_date . '") as cancelled_by_4u')
            )
            ->addSelect(
                DB::raw('(select count(*) FROM orders as canceled_by_other_reason_orders left join order_logs on canceled_by_other_reason_orders.id = order_logs.order_id WHERE canceled_by_other_reason_orders.shopname = client_shops.id AND order_logs.id IN (SELECT MAX(order_logs_last_attempt.id) from order_logs as order_logs_last_attempt where order_logs_last_attempt.order_id = canceled_by_other_reason_orders.id) AND (order_logs.canceled_by != "4u" or order_logs.canceled_by IS null) AND canceled_by_other_reason_orders.status_id in (' . \App\OrderStatus::CANCEL . ', ' . \App\OrderStatus::CLIENT_RETURN_ACCEPTED . ', ' . \App\OrderStatus::CANCEL_REQUEST_ACCEPTED . ') AND canceled_by_other_reason_orders.created_at >= "' . $from_date . '" AND canceled_by_other_reason_orders.created_at <= "' . $to_date . '") as cancelled_by_other_reason')
            );

       
        $avgCompletion = (clone $baseQuery)
            ->addSelect(DB::raw('(
                SELECT AVG(TIMESTAMPDIFF(SECOND, orders.created_at, NOW()))
                FROM orders
                WHERE orders.shopname = client_shops.id
                AND orders.created_at >= "'.$from_date.'"
                AND orders.created_at <= "'.$to_date.'"
            ) as avg_time'));

       
        $cancelOrders = DB::table('orders')
            ->leftJoin('clients','clients.id','=','orders.client_id')
            ->leftJoin('users','users.id','=','clients.user_id')
            ->leftJoin('client_shops','client_shops.id','=','orders.shopname')
            ->leftJoin('order_logs','orders.id','=','order_logs.order_id')
            ->whereBetween('order_logs.created_at',[$from_date,$to_date]);

        return  $this->interface->getClientShopOrders($clientShopsOrders,$perPage);
    }

    

}