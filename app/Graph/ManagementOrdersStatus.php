<?php
namespace App\Graph;

use App\OrderReport;
use App\OrderStatus;

class ManagementOrdersStatus implements Graph
{
    public function data()
    {
        $startDate = request()->has('from_date') ? now()->parse(request()->get('from_date'))->startOfDay() : now()->startOfDay();
        $endDate   = request()->has('to_date') ? now()->parse(request()->get('to_date'))->endOfDay() : now()->endOfDay();

        $quadrant = request()->get('quadrant');

        $query = OrderReport::excludeQuadrants('shop_region.quadrant_id')->belongsToMe()
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->whereBetween('order_reports.final_status_at', [$startDate, $endDate]);

        if ($quadrant) {
            $query->where(function ($q) use ($quadrant) {
                $q->WhereRaw('shop_region.quadrant_id = ?', [$quadrant]);
            })
            ;
        }

        $new_orders      = (clone $query)->where('order_reports.status_id', '=', OrderStatus::NEW_ORDER)->count();
        $on_going_orders = (clone $query)->whereIn('order_reports.status_id', [
            OrderStatus::ACCEPT,
            OrderStatus::START_RIDE,
            OrderStatus::REACHED_SHOP,
            OrderStatus::PICKED,
            OrderStatus::PICKED_UP,
            OrderStatus::SHIPPED,
            OrderStatus::REACHED_DESTINATION,
            OrderStatus::REROUTED,
        ])->count();
        $ticket          = (clone $query)->whereIn('order_reports.status_id', [OrderStatus::TICKET_RAISED, OrderStatus::PENDING])->count();
        $pending         = (clone $query)->where('order_reports.status_id', '=', OrderStatus::PENDING)->count();
        $client_returns  = (clone $query)->where('order_reports.status_id', '=', OrderStatus::RETURN_TO_CLIENT)->count();
        $return_accepted = (clone $query)->whereIn('order_reports.status_id', [
            OrderStatus::FORYOU_RETURN_ACCEPTED,
            OrderStatus::CLIENT_RETURN_ACCEPTED,
            OrderStatus::CLIENT_RETURN_DECLINE,
        ])->count();
        $cancelled = (clone $query)->whereIn('order_reports.status_id', [
            OrderStatus::CANCEL, OrderStatus::CANCEL_REQUEST_ACCEPTED,
        ])->count();
        $delivered = (clone $query)->where('order_reports.status_id', '=', OrderStatus::DELIVERED)->count();

        return [
            "new_orders"      => $new_orders,
            "on_going_orders" => $on_going_orders,
            "ticket"          => $ticket,
            "pending"         => $pending,
            "client_returns"  => $client_returns,
            "return_accepted" => $return_accepted,
            "cancelled"       => $cancelled,
            "delivered"       => $delivered,
        ];
    }
}
