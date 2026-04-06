<?php
namespace App\Graph;

use App\Client;
use App\ClientShop;
use App\OrderStatus;

class StoreBasedDeliveries implements Graph {
    public function data($request = null)
    {

        // $startDate = request()->has('from_date') ? now()->parse(request()->get('from_date'))->startOfDay() : now()->startOfDay();
        // $endDate = request()->has('to_date') ? now()->parse(request()->get('to_date'))->endOfDay() : now()->endOfDay();
        $fromDate = $request->get('from_date', now()->format('Y-m-d'));
        $toDate = $request->get('to_date', now()->format('Y-m-d'));

        //This is for showing the date range in the dashboard
        $fromDateTime = \Carbon\Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDateTime =  \Carbon\Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        //This is for filtering the orders based on the date range
        $startDate = $fromDateTime->format('Y-m-d H:i:s');
        $endDate =  $toDateTime->format('Y-m-d H:i:s');

        $client = request()->has('client') ? request()->get('client') : 3;

        $store_name = Client::select('id', 'user_id')->with('user:name,id')->find($client);
        $sub_title = $store_name->user->name;

        $labels = [];
        $datasets = [
            [
                'label' => 'Delivered',
                'data' => [],
                'borderColor' => '#166534',
                'backgroundColor' => '#22c55e'
            ],
            [
                'label' => 'Canceled',
                'data' => [],
                'borderColor' => '#991b1b',
                'backgroundColor' => '#ef4444'
            ]
        ];

        $data = ClientShop::query()
            ->select('name')
            ->withCount([
                'orders as delivered_orders' => function($query) use ($startDate, $endDate) {
                    $query
                        ->where('status_id', OrderStatus::DELIVERED)
                        ->whereBetween('orders.delivery_date', [$startDate, $endDate]);
                },
                'orders as canceled_orders' => function($query) use ($startDate, $endDate) {
                    $query
                        ->where('status_id', OrderStatus::CANCEL)
                        ->whereBetween('orders.delivery_date', [$startDate, $endDate]);
                }
            ])
            ->where('client_id', $client)
            ->toBase()
            ->get();

        foreach ($data as $key => $value) {
            $labels[] = $value->name;
            $datasets[0]['data'][] = $value->delivered_orders;
            $datasets[1]['data'][] = $value->canceled_orders;
        }

        return [
            'data' => [
                'labels' =>  $labels,
                'datasets' => $datasets
            ],
            'client' => $sub_title
        ];
    }
}