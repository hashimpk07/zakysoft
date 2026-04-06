<?php

namespace App\Graph;

use App\OrderReport;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;

class ClientDeliverySuccess implements Graph
{
    public function data()
    {
        $startDate = request()->has('from_date') ? now()->parse(request()->get('from_date'))->startOfDay() : now()->startOfDay();
        $endDate = request()->has('to_date') ? now()->parse(request()->get('to_date'))->endOfDay() : now()->endOfDay();
        $client = request()->get('client');

        $colors = [
            '#156082', // cancelled color
            '#e97132', // cancelled color
        ];
        $data = OrderReport::query()
            ->select(
                DB::raw('count(*) as count'),
                'status_id'
            )
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->withinDateRange($startDate, $endDate, 'order_reports.final_status_at')
            ->whereIn('order_reports.status_id', [OrderStatus::DELIVERED, OrderStatus::CANCEL])
            ->excludeQuadrants()
            ->belongsToMe()
            ->when($client, function ($query, $client) {
                return $query->where('order_reports.client_id', '=', $client);
            })
            ->toBase()
            ->groupBy('status_id')
            ->orderBy('status_id', 'asc')
            ->get();


        $values = [($data[0]->count ?? 0), ($data[1]->count ?? 0)];
        $labels = ['Delivered', 'Canceled'];

        return compact('colors', 'values', 'labels');
    }
}
