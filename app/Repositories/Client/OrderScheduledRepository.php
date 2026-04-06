<?php

namespace App\Repositories\Client;

use App\Order;
use App\Client;
use App\ClientShop;
use App\DeliveryType;
use Illuminate\Support\Facades\Log;
use App\Filter\OrderFilter;

class OrderScheduledRepository
{
    public function getScheduledOrders(OrderFilter $request)
    {
        try {
            $orders = Order::select(
                    'orders.code',
                    'orders.client_order_id',
                    'orders.delivery_date',
                    'orders.status_id',
                    'orders.id',
                    'orders.delivery_time',
                    'orders.client_id',
                    'orders.zone_id',
                    'orders.region_id',
                    'orders.shopname'
                )->with([
                    'shop:id,name,zone_id',
                    'shop.zone:id,name',
                    'shop.region:regions.id,regions.name',
                    'progress:id,name',
                ])->where('delivery_type', DeliveryType::SCHEDULES)
                    ->withRegionZone()
                    ->withClient()
                    ->belongsToMe()
                    ->filter($request)
                    ->orderByDesc('orders.id')
                    ->paginate(10)
                    ->withQueryString();

            $orders->getCollection()->transform(function ($order) {
                return [
                    'id' => $order->id,
                    'code' => $order->code,
                    'client_order_id' => $order->client_order_id,
                    'delivery_date' => $order->delivery_date,
                    'status_id' => $order->status_id,
                    'delivery_time' => $order->delivery_time,
                    'shopName' => $order->shop?->name,
                    'zone_name' => $order->shop?->zone?->name,
                    'region_name' => $order->shop?->region?->name,
                    'progressName' => $order->progress?->name, 
                ];
            });
            return $orders; 
        } catch (\Exception $e) {
            Log::error('Error fetching scheduled orders: ' . $e->getMessage());
            return null; 
        }
    }

    public function getClients()
    {
        return Client::query()
            ->with('user')
            ->belongsToMe()
            ->get();
    }

    public function getShops()
    {
        return ClientShop::query()
            ->belongsToMe()
            ->addSelect([
                'client_name' => Client::select('users.name')
                    ->leftJoin('users', 'users.id', 'clients.user_id')
                    ->whereColumn('clients.id', 'client_shops.client_id')
            ])
            ->get();
    }
}
