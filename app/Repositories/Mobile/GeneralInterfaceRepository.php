<?php

namespace App\Repositories\Mobile;

use App\Captain;
use App\CaptainLocationLog;
use App\CompanyInformation;
use App\Interfaces\Mobile\GeneralInterface;
use App\Order;
use App\OrderPendingReason;
use App\OrderStatus;
use Illuminate\Database\Eloquent\Model;

class GeneralInterfaceRepository implements GeneralInterface
{
    public function updateCaptain(Captain $captain, array $data)
    {
        return $captain->update($data);
    }

    public function getCompanyInformation(): CompanyInformation
    {
        return CompanyInformation::firstOrFail();
    }

    public function findOrderById(int $id): Model|Order|null
    {
        return Order::find($id);
    }

    public function findOrderStatusById(int $id): Model|OrderStatus|null
    {
        return OrderStatus::find($id);
    }

    public function getOrderPendingReasonById(int $id): ?string
    {
        return OrderPendingReason::where('id', $id)->value('reason');
    }

    public function checkAnyShippedOrders(array $orderIds, int $captainId): bool
    {
        return Order::where('captain_id', $captainId)
            ->status([OrderStatus::SHIPPED, OrderStatus::REACHED_DESTINATION, OrderStatus::REROUTED])
            ->whereNotIn('id', $orderIds)
            ->exists();
    }

    public function createCaptainLocationLog(array $data): CaptainLocationLog|Model
    {
        return CaptainLocationLog::create($data);
    }

    public function findCaptainById(int $id)
    {
        return Captain::find($id);
    }

    public function getRecentCaptainLocationLog(int $captain_id)
    {
        return CaptainLocationLog::where('captain_id', $captain_id)->latest('last_updated_at')->first();
    }

    public function checkOrderProofOfPickup(array $orderIds, int $captainId): bool{
        return Order::where('status_id', OrderStatus::REACHED_SHOP)->whereIn('id', $orderIds)->where("captain_id", $captainId)->exists();
    }

     public function checkProofOfPickupEnabled(array $orderIds): bool{
        return Order::whereIn('id', $orderIds)
                 ->whereHas('client', function($query) {
                     $query->where('proof_of_pickup', true);
                 })
                 ->exists();
     }
}
