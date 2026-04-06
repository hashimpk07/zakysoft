<?php
namespace App\Graph;

use App\Client;
use App\Order;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;

class ConsumedAverageDeliveryTime implements Graph {
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

        $colors = '#9BD0F5';
        $labels = [];
        $values = [];

        $data = Client::query()
            ->select('id', 'user_id')
            ->with('user:name,id')
            ->addSelect(['average_consumed_time' => Order::query()
                ->select(DB::raw('AVG(TIMESTAMPDIFF(MINUTE,
                        (SELECT created_at FROM order_logs WHERE order_logs.status_id = '. OrderStatus::NEW_ORDER . ' AND order_logs.order_id = orders.id ORDER BY id desc LIMIT 1),
                        (IFNULL((SELECT created_at FROM order_logs WHERE order_logs.status_id = '. OrderStatus::DELIVERED . ' AND order_logs.order_id = orders.id ORDER BY id desc LIMIT 1), now()))
                    )) as processing_time')
                )
                ->where('status_id', OrderStatus::DELIVERED)
                ->whereColumn('orders.client_id', 'clients.id')
                ->withinDateRange($startDate, $endDate)
                ->limit(1)
            ])
            ->whereHas('orders', function($query) use ($startDate, $endDate) {
                $query->whereBetween('orders.created_at', [$startDate, $endDate]);
            })
            ->belongsToMe()
            ->get();

            
        foreach ($data as $key => $value) {
            $labels[] = $value->user->name;
            $values[] = number_format($value->average_consumed_time, 2);
        }

        return compact('colors', 'labels', 'values');
    }
}