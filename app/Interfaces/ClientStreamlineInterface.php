<?php
namespace App\Interfaces;

use App\Order;
use App\User;

interface ClientStreamlineInterface
{
    public function getOrders(array $filters, array $shopIds);
    public function countByStatus(array $statusIds, array $shopIds): int;
    public function countWithClientChat(array $shopIds): int;
    public function getPermissibleShopIdsForUser(User $user): array;
    public function getShopsWithOrders(array $filters, array $shopIds);
     public function getCaptains(array $filters, array $shopIds, ?Order $order);
}
