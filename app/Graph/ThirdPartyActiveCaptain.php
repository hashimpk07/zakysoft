<?php

namespace App\Graph;

use App\Captain;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;

class ThirdPartyActiveCaptain implements Graph
{
    public function data()
    {
        $colors = [
            '#10b981',
            '#eab308',
            '#f97316',
            '#ef4444',
            '#84cc16',
            '#22c55e',
            '#10b981',
            '#14b8a6',
            '#06b6d4',
            '#0ea5e9',
            '#3b82f6',
            '#6366f1',
            '#8b5cf6',
            '#a855f7',
            '#d946ef',
            '#ec4899',
            '#f43f5e',
            '#1e40af',
            '#6b21a8',
            '#831843',
            '#9f1239',
            '#3b0764',
            '#9333ea',
            '#0c4a6e',
            '#22c55e'
        ];
        $labels = [];
        $values = [];

        $company_id_3pl = session('company_id_3pl') ?? request('company_id_3pl');
        $captains = Captain::online()
            ->select(
                DB::raw('count(*) as count'),
                'order_statuses.name as name',
            )
            ->leftJoin('orders', 'orders.captain_id', 'captains.id')
            ->leftJoin('order_statuses', 'order_statuses.id', 'orders.status_id')
            ->belongsto3pl($company_id_3pl)
            ->whereNotIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::CLIENT_RETURN_ACCEPTED])
            ->groupBy('order_statuses.id', 'order_statuses.name')
            ->excludeQuadrants()
            ->toBase()
            ->get();

        foreach ($captains as $key => $captain) {
            $labels[] = $captain->name;
            $values[] = $captain->count;
        }

        return compact('values', 'labels', 'colors');
    }
}