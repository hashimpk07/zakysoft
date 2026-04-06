<?php

namespace App\Interfaces;

use App\Order;
use Illuminate\Http\Request;

interface ThirdPartyStreamlineInterface
{
    public function getOrders(Request $request, array $status);
    public function getShops(Request $request);
    public function orderCountByStatus(int|array $statuses, int|string $company_id): int;
    public function findOrderById($id);
    public function getCaptains(Request $request, ?Order $order = null);

    public function getCaptainStats($captainId);
}
