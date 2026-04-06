<?php
namespace App\Graph;

use App\Client;
use App\Order;
use App\OrderStatus;

class Growth implements Graph {
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

        $current_month_start = now()->endOfMonth() == $endDate ? now()->parse($endDate)->startOfMonth() : now()->parse($endDate)->subMonth()->startOfMonth();
        $current_month_end = now()->endOfMonth() == $endDate ? $endDate : now()->subMonth()->endOfMonth();

        $previous_month_start = now()->parse($current_month_start)->subMonth()->startOfMonth();
        $previous_month_end = now()->parse($previous_month_start)->endOfMonth();

        $labels = [];
        $datasets = [
            [
                'label' => 'Current -' .$current_month_start->format('M-y'),
                'data' =>  [],
                'backgroundColor' =>  '#16a34a',
                'borderColor' => '#22c55e',

            ],
            [
                'label' => 'Previous -' .$previous_month_start->format('M-y'),
                'data' =>  [],
                'backgroundColor' =>  '#991b1b',
                'borderColor' => '#dc2626',
            ],
        ];

        $data = Client::query()
            ->select('id', 'user_id')
            ->with('user:name,id')
            ->withCount([
                'orders as current' => function($query) use ($current_month_start, $current_month_end) {
                    $query->whereBetween('delivery_date', [$current_month_start, $current_month_end])
                        ->where('status_id', OrderStatus::DELIVERED);
                }, 
                'orders as previous' => function($query) use ($previous_month_start, $previous_month_end) {
                    $query->whereBetween('delivery_date', [$previous_month_start, $previous_month_end])
                        ->where('status_id', OrderStatus::DELIVERED);
                },
            ])
            ->belongsToMe()
            ->get();

        foreach ($data as $key => $value) {
            if($value->current or $value->previous) {
                $labels[] = $value->user->name;
                $datasets[0]['data'][] = $value->current;
                $datasets[1]['data'][] = $value->previous;
            }
        }

        return compact('datasets', 'labels');
    }
}