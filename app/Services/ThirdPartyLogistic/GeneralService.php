<?php
namespace App\Services\ThirdPartyLogistic;

use App\ClientShop;
use App\Interfaces\ThirdPartyLogisticInterface;
use App\Order;
use App\OrderStatus;
use App\Services\MapboxService;

class GeneralService
{
    public function __construct(private readonly ThirdPartyLogisticInterface $interface)
    {
    }

    public function getCaptainsFor3PL($userId)
    {
        return $this->interface->getCaptains($userId);
    }

    public function getOrderStatusFor3PL()
    {
        return $this->interface->getOrderStatus();
    }

    public function getVehiclesFor3PL(int $companyId, ?int $type = null, $assigned = true)
    {
        return $this->interface->getVehiclesFor3PL(companyId: $companyId, type: $type, assigned: $assigned);
    }

    public function getOrderDetails($companyId, $orderId)
    {
        $order = $this->interface->findOrderWithRelations(id: $orderId, companyId: $companyId);
        if (!$order) {
            return ["status" => false];
        }
        return [
            "status" => true,
            'order' => $order,
        ];

    }

    public function getOrderPayment(int $id)
    {
        return $this->interface->getOrderPayment(id: $id);
    }

    public function getOrderCounts($companyId): array
    {

        $onGoingStatuses = [
            OrderStatus::ACCEPT,
            OrderStatus::START_RIDE,
            OrderStatus::REACHED_SHOP,
            OrderStatus::PICKED,
            OrderStatus::PICKED_UP,
            OrderStatus::SHIPPED,
            OrderStatus::REACHED_DESTINATION,
            OrderStatus::REROUTED,
        ];

        $complaintStatuses = [
            OrderStatus::TICKET_RAISED,
            OrderStatus::PENDING,
        ];

        return [
            'on_going_orders_count' => $this->interface
                ->getOrderCounts(companyId: $companyId, status: $onGoingStatuses),
            'complaints_orders_count' => $this->interface
                ->getOrderCounts(companyId: $companyId, status: $complaintStatuses),
            'client_return_orders_count' => $this->interface
                ->getOrderCounts(companyId: $companyId, status: [OrderStatus::RETURN_TO_CLIENT]),
            'request_for_cancel_orders_count' => $this->interface
                ->getOrderCounts(companyId: $companyId, status: [OrderStatus::REQUEST_FOR_CANCEL, OrderStatus::CANCEL, OrderStatus::CANCEL_REQUEST_ACCEPTED]),
        ];
    }

    public function getOrderDirections(Order $order)
    {
        $orderLocation = !empty($order->location) ? explode(",", $order->location) : [];
        $shop = ClientShop::with('region')->withCount('newOrders')->find($order->shopname);
        $shopLocation = !empty($shop?->location) ? explode(",", $shop->location) : [];

        $captainAssigned = !empty($order->captain);

        // Validate coordinates safely
        $fromLng = isset($orderLocation[0]) ? (float) $orderLocation[0] : null;
        $fromLat = isset($orderLocation[1]) ? (float) $orderLocation[1] : null;

        $toLng = isset($shopLocation[0]) ? (float) $shopLocation[0] : null;
        $toLat = isset($shopLocation[1]) ? (float) $shopLocation[1] : null;
        $mapBox = app(MapboxService::class);

        $routes = $mapBox->getRoute($fromLng, $fromLat, $toLng, $toLat);

        $shopData = [
            "name" => $shop->name,
            "order_count" => $shop->new_orders_count,
            "location" => $shop->location,
            "region" => [
                "name" => $shop->region?->name,
                "id" => $shop->region?->id
            ]
        ];

        $vehicleTypeId = $order->captain?->vehicle?->vehicleType?->id;

        $vehicleIcon = ($vehicleTypeId == 4)
            ? 'bike'
            : 'van';

        $captain = [
            "assigned" => $captainAssigned,
            "data" => [
                "name" => optional($order->captain->user)->name,
                "phone" => optional($order->captain)->phone_number,
                "vehicle" => [
                    "type_id" => $vehicleTypeId,
                    "icon" => $vehicleIcon
                ]
            ]
        ];
        $distance = number_format($order->shop_to_delivery_km, 2) . " km";

        return [
            "id" => $order->id,
            "routes" => $routes,
            "shop" => $shopData,
            "captain" => $captain,
            "distance" => $distance,
            "order_location" => $order->location,
            "shop_location" => $shop->location
        ];

    }

}
