<?php

namespace App\Services\Client;

use App\Client;
use App\Interfaces\ClientInterface;
use App\OrderStatus;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardService
{
    public function __construct(private readonly ClientInterface $interface)
    {
    }

    public function getDashboardData(Request $request, $user): array
    {
        $client = $this->getClientFromUser($user);

        $shopIds = $user->clientShops()->pluck('id')->toArray();

        $baseQuery = $this->interface->getClientOrdersQuery($client->id, $shopIds);

        $defaultDate = now()->format('Y-m-d');

        [$startDate, $endDate] = getSystemTimeRange(
            fromDate: $request->get('from_date', $defaultDate),
            toDate: $request->get('to_date', $defaultDate)
        );

        // ---- Status Counts
        $statusMap = [
            'total_orders' => null,
            'delivered_orders' => OrderStatus::DELIVERED,
            'shipped' => OrderStatus::SHIPPED,
            'canceled' => OrderStatus::CANCEL,
            'returned' => OrderStatus::RETURN_TO_CLIENT,
            'pending' => OrderStatus::PENDING,
        ];

        $counts = [];

        foreach ($statusMap as $key => $statusId) {
            $counts[$key] = $this->interface->countByStatus($baseQuery, $statusId, $startDate, $endDate);
        }

        return $counts;
    }

    public static function getClientFromUser(User $user): Client
    {
        $clientId = $user->employeeClient->value('user_id');
        return Client::where('user_id', $clientId)->firstOrFail();
    }

    public function getUserOrderStats(User $user, Request $request): array
    {
        $client = $this->getClientFromUser($user);

        $shopIds = $user->clientShops()->pluck('id')->toArray();

        $baseQuery = $this->interface->getClientOrdersQuery($client->id, $shopIds);

        return [
            'total_orders' => $this->interface->countByStatus($baseQuery),
            'delivered_orders' => $this->interface->countByStatus($baseQuery, OrderStatus::DELIVERED),
            'new_orders_count' => $this->interface->countNewOrders($user, $request),
            'assign_attempts_orders_count' => $this->interface->countAssignableAttempts($user, $request),
            'on_going_orders_count' => $this->interface->countOngoing($user, $request),
            'ticket_raised_orders_count' => $this->interface->countByStatusList($user, $request, [OrderStatus::TICKET_RAISED]),
            'pending_orders_count' => $this->interface->countByStatusList($user, $request, [OrderStatus::PENDING]),
            'client_return_orders_count' => $this->interface->countByStatusList($user, $request, [OrderStatus::RETURN_TO_CLIENT]),
            'client_cancel_orders_count' => $this->interface->countByStatusList($user, $request, [OrderStatus::CANCEL,OrderStatus::CANCEL_REQUEST_ACCEPTED,OrderStatus::REQUEST_FOR_CANCEL]),
        ];
    }

    public function getSalesReportData($user, $request): array
    {
        $client = $this->getClientFromUser($user);

        $query = $this->interface->salesReportQuery($client->id);

        $counts = [
            'shipped' => (clone $query)->where('orders.status_id', OrderStatus::SHIPPED)->count(),
            'delivered' => (clone $query)->where('orders.status_id', OrderStatus::DELIVERED)->count(),
            'cancelled' => (clone $query)->whereIn('orders.status_id', [OrderStatus::CANCEL, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::REQUEST_FOR_CANCEL])->count(),
            'total_orders' => (clone $query)->whereIn('orders.status_id', [OrderStatus::SHIPPED, OrderStatus::DELIVERED, OrderStatus::CANCEL, OrderStatus::FORYOU_RETURN_ACCEPTED, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED])->count(),
        ];

        return [
            'filters' => [
                'from_date' => $request->input('from_date', now()->startOfMonth()->format('Y-m-d')),
                'to_date' => $request->input('to_date', now()->format('Y-m-d')),
            ],
            'counts' => $counts,
        ];
    }

    public function getOrderStatusGraphCount($user)
    {
        $client = $this->getClientFromUser($user);

        $shopIds = $user->clientShops()->pluck('id')->toArray();

        $baseQuery = $this->interface->getClientOrdersQuery($client->id, $shopIds);

        return $this->interface->getOrderStatusGraphCount($baseQuery);
    }

    public function getOrderStatusMonthCount($user)
    {
        $client = $this->getClientFromUser($user);
        $shopIds = $user->clientShops()->pluck('id')->toArray();
        $year = now()->year;

        $startDate = Carbon::create($year, 1, 1, 6)->format('Y-m-d H:i:s');
        $endDate = Carbon::create($year + 1, 1, 1, 5, 59, 59)->format('Y-m-d H:i:s');

        $orders = $this->interface->getDeliveredOrdersGroupedByMonth(clientId: $client->id, shopIds: $shopIds, startDate: $startDate, endDate: $endDate);

        return $this->formatMonthlyData($orders);
    }

    private function formatMonthlyData($orders): array
    {
        $monthsMap = [
            1 => 'Jan',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Apr',
            5 => 'May',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Aug',
            9 => 'Sep',
            10 => 'Oct',
            11 => 'Nov',
            12 => 'Dec',
        ];

        // Initialize all months with value 0
        $monthlyData = [];
        foreach ($monthsMap as $index => $label) {
            $monthlyData[$index] = [
                'month' => $label,
                'value' => 0,
            ];
        }

        // Fill actual data from orders
        foreach ($orders as $order) {
            $index = (int) $order->month;
            if (isset($monthlyData[$index])) {
                $monthlyData[$index]['value'] = $order->count;
            }
        }

        return array_values($monthlyData);
    }
}
