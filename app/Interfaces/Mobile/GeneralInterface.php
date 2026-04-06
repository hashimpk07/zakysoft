<?php

namespace App\Interfaces\Mobile;

use App\Captain;
use App\CompanyInformation;
use App\Order;
use App\OrderStatus;
use Illuminate\Database\Eloquent\Model;

interface GeneralInterface{
    public function updateCaptain(Captain $captain, array $data);
    public function getCompanyInformation(): CompanyInformation;

    public function findOrderById(int $id): Model|Order|null;

    public function findOrderStatusById(int $id): Model|OrderStatus|null;

    public function getOrderPendingReasonById(int $id): ?string;

    public function checkAnyShippedOrders(array $orderIds, int $captainId): bool;

    public function createCaptainLocationLog(array $data);

    public function findCaptainById(int $id);

    public function getRecentCaptainLocationLog(int $id);

    public function checkOrderProofOfPickup(array $orderIds, int $captainId):bool;
    public function checkProofOfPickupEnabled(array $orderIds):bool;
}