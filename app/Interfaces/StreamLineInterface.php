<?php

namespace App\Interfaces;

use App\Order;
use Closure;
use Illuminate\Http\Request;

interface StreamLineInterface
{
    public function getAreas();
    public function getClients();
    public function getBaseClientShops();
    public function getClientShops(Request $request);

    public function getStreamLineOrders(Request $request, $ticket_type);
    public function generateStreamLineStatistics();
    public function isCaptainAssignable(?Order $order): bool;
    public function getCaptains(Request $request, ?Order $order);
    public function findOrder(int $orderId, ?Closure $closure = null);
    public function getCaptainBaseQuery(Request $request);
    public function findOrderWithDetails($orderId);
    public function getTodayCaptainStats(int $captainId): array;
}
