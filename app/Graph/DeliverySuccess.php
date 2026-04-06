<?php

namespace App\Graph;

use App\Order;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;

class DeliverySuccess implements Graph
{
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

        $colors = [
            '#4CAF50', 
            '#FF9800', 
            '#2196F3',
            '#9C27B0', 
            '#FFC107',
            '#FF5722',
            '#00BCD4', 
            '#607D8B', 
            '#8BC34A', 
            '#795548', 
        ];

        $data = Order::query()
            ->select(
                DB::raw('count(*) as count'),
                'status_id'
            )
            ->withinDateRange($startDate, $endDate, 'delivery_date')
            ->whereIn('status_id', [OrderStatus::DELIVERED, OrderStatus::CANCEL])
            ->belongsToMe()
            ->toBase()
            ->groupBy('status_id')
            ->orderBy('status_id', 'asc')
            ->get();

        $values = [($data[0]->count ?? 0), ($data[1]->count ?? 0)];
        $labels = ['Delivered', 'Canceled'];

        return compact('colors', 'values', 'labels');
    }
}
