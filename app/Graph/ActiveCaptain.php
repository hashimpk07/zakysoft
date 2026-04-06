<?php
namespace App\Graph;

use App\Captain;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;

class ActiveCaptain implements Graph
{
    public function data()
    {
        $colors = [
            '#36a2eb',
            '#ff6384',
            '#ff9f40',
            '#ffcd56',
            '#14b8a6',
            '#6b7280',
            '#1f6f70',
            '#b5d1af',
            '#5f4c60',
            '#97a7c4',
            '#F5921B',
            '#e1a692',
            '#54bebe',
            '#dedad2',
            '#badbdb',
            '#e8daff',
            '#ff8389',
            '#3ddbd9',
            '#20B2AA',
            '#ADD8E6',
            '#90EE90',
            '#FF6347',
        ];
        $labels = [];
        $values = [];

        $captains = Captain::online()
            ->select(
                DB::raw('count(*) as count'),
                'order_statuses.name as name',
            )
            ->leftJoin('orders', 'orders.captain_id', 'captains.id')
            ->leftJoin('order_statuses', 'order_statuses.id', 'orders.status_id')
            ->whereNotIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED])
            ->groupBy('order_statuses.id', 'order_statuses.name')
            ->excludeQuadrants()
            ->belongsToMe()
            ->toBase()
            ->get();

        foreach ($captains as $key => $captain) {
            $labels[] = $captain->name;
            $values[] = $captain->count;
        }

        return compact('values', 'labels', 'colors');
    }
}
