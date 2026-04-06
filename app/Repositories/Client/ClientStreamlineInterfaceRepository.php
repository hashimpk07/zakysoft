<?php

namespace App\Repositories\Client;

use App\Captain;
use App\ClientShop;
use App\Interfaces\ClientStreamlineInterface;
use App\Order;
use App\User;

class ClientStreamlineInterfaceRepository implements ClientStreamlineInterface
{
    public function getOrders(array $filters, array $shopIds)
    {
        return Order::query()
            ->select([
                'orders.id',
                'orders.client_order_id',
                'orders.captain_id',
                'orders.dispatch_at',
                'orders.status_id',
                'orders.created_at',
                'orders.location',
                'orders.delivery_date',
                'orders.delivery_type',
                'orders.shopname',
                'orders.shop_to_delivery_km',
                'orders.scheduled_delivery_time_slot_id',
                'orders.client_id',
                'orders.delivery_type',
            ])
            ->with([
                'openComplaint:id,order_id',
                'progress',
                'client:id,user_id',
                'captain:id',
                'captain.location:id,captain_id,latitude,longitude',
            ])
            ->withClient()
            ->withCaptain()
            ->withShop()
            ->withShopRegionAndZone()
            ->withLastLocation()
            ->belongsToMe()
            ->whereIn('shopname', $shopIds)
            ->when(
                !$filters['has_client_chat'] && !empty($filters['status']),
                function ($q) use ($filters) {
                    $status = $filters['status'];

                    is_array($status)
                        ? $q->whereIn('status_id', $status)
                        : $q->where('status_id', $status);
                }
            )
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(
                    fn($q) =>
                    $q->where('client_order_id', 'like', "%{$search}%")
                        ->orWhere('shopname', 'like', "%{$search}%")
                );
            })
            ->when(($filters['has_client_chat'] ?? false),
                fn($q) =>
                $q->whereHas('openComplaint')
            )
            ->orderBy('orders.id')
            ->get()
            ->map(function ($order) {
                $order->time_left = $order->endTime();
                $order->created_formatted_at = $order->created_at->format('d-m-Y h:i:s A');
                $order->open_ticket = $order->openComplaint;
                unset($order->openComplaint);
                return $order;
            });
    }

    public function countByStatus(array $statusIds, array $shopIds): int
    {
        return Order::belongsToMe()
            ->whereIn('shopname', $shopIds)
            ->whereIn('status_id', $statusIds)
            ->count();
    }

    public function countWithClientChat(array $shopIds): int
    {
        return Order::belongsToMe()
            ->whereIn('shopname', $shopIds)
            ->whereHas('openComplaint')
            ->count();
    }

    public function getPermissibleShopIdsForUser(User $user): array
    {
        // Employee client users → limited shops
        if ($user->employeeClient?->isNotEmpty()) {
            return $user->clientShops()
                ->pluck('id')
                ->toArray();
        }

        // Admin / internal users → all shops
        return ClientShop::pluck('id')->toArray();
    }

    public function getShopsWithOrders(array $filters, array $shopIds)
    {
        return ClientShop::query()
            ->withLogo()
            ->withClient()
            ->whereIn('id', $shopIds)
            ->whereHas('orders', function ($query) use ($filters, $shopIds) {
                $query
                    ->belongsToMe()
                    ->whereIn('shopname', $shopIds)
                    ->when($filters['status'] ?? null, function ($q, $status) {
                        is_array($status)
                            ? $q->whereIn('status_id', $status)
                            : $q->where('status_id', $status);
                    })
                    ->when($filters['search'] ?? null, function ($q, $search) {
                        $q->where(
                            fn($q) =>
                            $q->where('client_order_id', 'like', "%{$search}%")
                                ->orWhere('shopname', 'like', "%{$search}%")
                        );
                    })
                    ->when(
                        ($filters['has_client_chat'] ?? false),
                        fn($q) => $q->whereHas('openComplaint')
                    );
            })
            ->get()
            ->map(fn($shop) => [
                'id'        => $shop->id,
                'text'      => $shop->name,
                'client_id' => $shop->client_id,
                'logo'      => $shop->logo,
                'client'    => $shop->client,
            ]);
    }

    public function getCaptains(array $filters, array $shopIds, ?Order $order)
    {
        [$fromTime, $toTime] = getSystemTimeRange(
            $filters['from_date'] ?? null,
            $filters['to_date'] ?? null
        );

        return Captain::query()
            ->select('captains.id', 'captains.phone_number', 'captain_employment_type_id')
            ->active()
            ->withName()
            ->withVehicleType()
            ->with([
                'currentShift',
                'location:captain_id,latitude,longitude',
                'regions:name,id',
                'currentOrder:id,client_id,client_order_id,shopname,captain_id',
                'currentOrder.client:id,user_id',
                'currentOrder.client.user:id,name',
                'currentOrder.shop:id,name',
                'document',
                'employmentType:id,name',
                'company',
            ])
            ->withCount('currentOrder')
            ->withCount([
                'deliveredOrders' => fn($q) =>
                $q->whereBetween('delivery_date', [$fromTime, $toTime]),
            ])
            ->whereHas(
                'currentOrder',
                fn($q) =>
                $q->whereIn('shopname', $shopIds)
            )
            ->when(
                $filters['area'] ?? null,
                fn($q, $area) =>
                $q->whereHas('regions', fn($q) => $q->where('regions.id', $area))
            )
            ->when(
                $filters['region'] ?? null,
                fn($q, $region) =>
                $q->whereHas('regions.quadrant', fn($q) => $q->where('quadrants.id', $region))
            )
            ->when(
                $filters['employment_type'] ?? null,
                fn($q, $type) =>
                $q->where('captains.captain_employment_type_id', $type)
            )
            ->when(
                $filters['company'] ?? null,
                fn($q, $company) =>
                $q->whereHas(
                    'company',
                    fn($q) =>
                    $q->where('third_party_logistic_companies.id', $company)
                )
            )
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->whereHas(
                        'user',
                        fn($q) =>
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                    )
                        ->orWhere('phone_number', 'like', "{$search}%")
                        ->orWhere('iqama_number', 'like', "{$search}%");
                });
            })
          // Safer if the intent is "filter when order exists":
            ->when($order, fn($q) => $q->where('captains.id', $order->captain_id))
            ->when(
                $filters['captain'] ?? null,
                fn($q, $id) =>
                $q->where('captains.id', $id)
            )
            ->when($filters['state'] ?? null, function ($q, $state) {
                match ($state) {
                    'all'      => $q->where(fn($q) => $q->onlineFree()->orWhereHas('currentOrder')),
                    'free'     => $q->onlineFree(),
                    'busy'     => $q->whereHas('currentOrder'),
                    'no_update' => $q->idle(),
                    'offline'  => $q->offline(),
                    default    => null,
                };
            })
            ->orderBy('name')
            ->get();
    }
}
