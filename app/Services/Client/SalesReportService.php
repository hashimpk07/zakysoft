<?php

namespace App\Services\Client;

use App\Client;
use App\Interfaces\ClientInterface;
use App\OrderStatus;
use App\User;

class SalesReportService 
{
    public function __construct(private readonly ClientInterface $interface) {}
    public function getSaleaReportData($user): array
    {
        $fromDate = request()->input('from_date', now()->startOfMonth()->format('Y-m-d'));
        $toDate   = request()->input('to_date', now()->format('Y-m-d'));
        $search = request()->input('search');

        $client = $this->getClientFromUser($user);
        $query = $this->interface->salesReportDataQuery($client->id,false,$fromDate,$toDate,$search);

        $counts = [
            'shipped' => (clone $query)->where('orders.status_id', OrderStatus::SHIPPED)->count(),
            'delivered' => (clone $query)->where('orders.status_id', OrderStatus::DELIVERED)->count(),
            'cancelled' => (clone $query)->whereIn('orders.status_id', [OrderStatus::CANCEL, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::REQUEST_FOR_CANCEL])->count(),
            'total_orders' => (clone $query)->whereIn('orders.status_id', [OrderStatus::SHIPPED, OrderStatus::DELIVERED, OrderStatus::CANCEL, OrderStatus::FORYOU_RETURN_ACCEPTED, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED])->count(),
        ];

        $orders = (clone $query)->orderBy('orders.id', 'DESC')->paginate(20)->withQueryString();

        return [
            'filters' => [
                'from_date' => $fromDate,
                'to_date' =>  $toDate,
            ],
            'counts' => $counts,
            'orders' => $orders,
        ];
    }

    public function getClientFromUser(User $user): Client
    {
        $clientId = $user->employeeClient->value('user_id');
        return Client::where('user_id', $clientId)->firstOrFail();
    }
   
}
