<?php
namespace App\Graph;

use App\Client;

class OrderByClient implements Graph {
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

        $clients = Client::with('user')
            ->belongsToMe()
            ->withCount([
                'order' => function($query) use ($startDate, $endDate) {
                    $query->whereBetween('orders.delivery_date', [$startDate, $endDate]);
                }
            ])
            ->orderBy('order_count', 'desc')
            ->get();

        $client_orders = [];
        $orders_count = 0;

        foreach ($clients as $key => $client) {
            if ($key < 14) {
                $client_orders['clients'][] = $client->user->name;
                $client_orders['order_count'][] = $client->order_count;
            } else {
                if($client->order_count <= 0) {
                    break;
                }
                
                $orders_count += $client->order_count;
            }
        }

        if ($orders_count > 0) {
            $client_orders['clients'][14] = 'Others';
            $client_orders['order_count'][14] =  $orders_count;
        }

        return $client_orders;
    }
}
