<?php

namespace App\Repositories\General;

use App\Http\Requests\General\Orders\PackageListRequest;
use App\Interfaces\General\PackageInterface;
use App\Order;
use App\OrderStatus;
use App\Package;
use App\PackageDeliveryRequest;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class PackageInterfaceRepository implements PackageInterface
{
    public function getPackages(PackageListRequest $request): LengthAwarePaginator
    {
        return Package::select('id', 'client_shop_id')->
            has('orders')
            ->notAssigned()
            ->whereHas('shop', function ($query) use ($request) {
                $query
                    ->where('auto_assignable', 1)
                    ->when($request->shop(), fn($q) => $q->where('id', $request->shop()))
                    ->when($request->client(), fn($q) => $q->where('client_id', $request->client()));
            })
            ->whereHas('directOrders', function ($query) use ($request) {
                $query
                    ->whereNotIn('status_id', [
                        OrderStatus::SHIPPED,
                        OrderStatus::DELIVERED,
                        OrderStatus::CANCEL,
                        OrderStatus::FORYOU_RETURN_ACCEPTED,
                        OrderStatus::CLIENT_RETURN_ACCEPTED,
                        OrderStatus::CANCEL_REQUEST_ACCEPTED,
                    ])
                    ->belongsToMe()
                    ->when($request->search(), fn($q) => $q->where('client_order_id', 'LIKE', $request->search() . '%'))
                    ->when($request->region(), fn($q) => $q->where('region_id', $request->region()));
            })
            ->with([
                'directOrders:orders.id,orders.client_order_id',
                'shop:id,name'
            ])
            ->latest()
            ->paginate($request->per_page())
            ->withQueryString();
    }

    public function getPackageRequests(int $packageId): array
    {
        $package = Package::findOrFail($packageId);
        $requests = PackageDeliveryRequest::where('package_id', $packageId)
            ->get()
            ->groupBy(fn($req) => now()->parse($req->sended_at)->format('Y-m-d H:i'));

        return compact('package', 'requests');
    }

    public function getRequestedCaptains(int $orderId, string $time): Collection
    {
        $order = Order::findOrFail($orderId);

        return $order->orderDeliveryRequests()
            ->with('captain')
            ->whereBetween('sended_at', [
                $time,
                now()->parse($time)->addMinute()->format('Y-m-d H:i') . ':00',
            ])
            ->get();
    }
}