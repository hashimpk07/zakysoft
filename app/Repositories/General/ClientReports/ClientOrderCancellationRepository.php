<?php

namespace App\Repositories\General\ClientReports;

use App\Interfaces\General\ClientReports\ClientOrderCancellationInterface;
use App\Order;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;


class ClientOrderCancellationRepository implements ClientOrderCancellationInterface
{
    public function getClientOrderCancellations(array $filters, int $perPage = 100)
    {
        return Order::query()
            ->select(
                'orders.id',
                'orders.client_order_id',
                'orders.created_at',
                'orders.status_id',
                'orders.client_id',
                'orders.captain_id',
                'orders.shopname',
                'order_statuses.name as status'
            )

            ->withShopRegionAndZone()  // provides shop_zone & shop_area
            ->withShop()
            ->withClient()
            ->withCaptain()
            ->withLastLog('lastLog.createdBy')

            ->leftJoin('order_statuses', 'order_statuses.id', '=', 'orders.status_id')

            ->when($filters['client_order_id'] ?? null, function ($query, $value) {
                $query->whereLike('orders.client_order_id', $value);
            })

            ->when($filters['shopname'] ?? null, function ($query, $value) {
                $query->where('orders.shopname', $value);
            })

            ->when($filters['client'] ?? null, function ($query, $value) {
                $query->where('orders.client_id', $value);
            })

            ->when($filters['captain'] ?? null, function ($query, $value) {
                $query->where('orders.captain_id', $value);
            })

            ->whereBetween('orders.created_at', [
                $filters['from_date'],
                $filters['to_date']
            ])

            ->status([
                OrderStatus::CANCEL,
                OrderStatus::FORYOU_RETURN_ACCEPTED,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
                OrderStatus::CANCEL_REQUEST_ACCEPTED,
            ])

            ->latest('orders.id')
            ->paginate($perPage)
            ->withQueryString();
    }
}