<?php
namespace App\Graph;

use App\Order;
use App\OrderStatus;

class OrdersStatus implements Graph
{
    public function data($request = null)
    {
        // $startDate = request()->has('from_date') ? now()->parse(request()->get('from_date'))->startOfDay() : now()->startOfDay();
        // $endDate   = request()->has('to_date') ? now()->parse(request()->get('to_date'))->endOfDay() : now()->endOfDay();

        $fromDate = $request->get('from_date', now()->format('Y-m-d'));
        $toDate = $request->get('to_date', now()->format('Y-m-d'));

        //This is for showing the date range in the dashboard
        $fromDateTime = \Carbon\Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDateTime =  \Carbon\Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        //This is for filtering the orders based on the date range
        $startDate = $fromDateTime->format('Y-m-d H:i:s');
        $endDate =  $toDateTime->format('Y-m-d H:i:s');

        $quadrant = request()->get('quadrant');
        
        $query    = Order::belongsToMe()->excludeQuadrants()->whereBetween('orders.delivery_date', [$startDate, $endDate]);
        if ($quadrant) {
            $query->where(function ($q) use ($quadrant) {
                $q->whereRaw('regions.quadrant_id = ?', [$quadrant])
                    ->orWhereRaw('shop_region.quadrant_id = ?', [$quadrant]);
            })
                ->leftJoin('regions', 'regions.id', 'orders.region_id')
                ->leftJoin('client_shops', 'client_shops.id', 'orders.shopname')
                ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
                ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id');
        }
        $new_orders      = (clone $query)->where('status_id', '=', OrderStatus::NEW_ORDER)->count();
        $on_going_orders = (clone $query)->whereIn('status_id', [
            OrderStatus::ACCEPT,
            OrderStatus::START_RIDE,
            OrderStatus::REACHED_SHOP,
            OrderStatus::PICKED,
            OrderStatus::PICKED_UP,
            OrderStatus::SHIPPED,
            OrderStatus::REACHED_DESTINATION,
            OrderStatus::REROUTED,
        ])->count();
        $ticket          = (clone $query)->whereIn('status_id', [OrderStatus::TICKET_RAISED, OrderStatus::PENDING])->count();
        $pending         = (clone $query)->where('status_id', '=', OrderStatus::PENDING)->count();
        $client_returns  = (clone $query)->where('status_id', '=', OrderStatus::RETURN_TO_CLIENT)->count();
        $return_accepted = (clone $query)->whereIn('status_id', [
            OrderStatus::FORYOU_RETURN_ACCEPTED,
            OrderStatus::CLIENT_RETURN_ACCEPTED,
            OrderStatus::CLIENT_RETURN_DECLINE,
        ])->count();
        $cancelled = (clone $query)->whereIn('status_id', [
            OrderStatus::CANCEL,
            OrderStatus::CANCEL_REQUEST_ACCEPTED,
            OrderStatus::REQUEST_FOR_CANCEL,
        ])->count();
        $delivered = (clone $query)->where('status_id', '=', OrderStatus::DELIVERED)->count();

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
