<?php
namespace App\Filter;

use App\Order;
use App\OrderStatus;

class OrderFilter extends Filter {

    public function clients($clients = [])
    {
        if(!is_array($clients)) {
            $clients = explode(',', $clients);
        }

        if(empty($clients)) {
            return;
        }


        $this->builder->whereIn('orders.client_id', $clients);
    }

    public function shopname($shop_name = [])
    {
        if(!is_array($shop_name)) {
            $shop_name = explode(',', $shop_name);
        }

        if(empty($shop_name)) {
            return;
        }

        $this->builder->whereIn('orders.shopname', $shop_name);
    }

    public function orderID($order_id = null)
    {
        if(!$order_id) {
            return;
        }

        $this->builder->where('orders.client_order_id', 'LIKE', '%' . $order_id . '%');
        $this->builder->orderBy('orders.client_order_id', 'asc');
    }

    public function order_type($type = null)
    {
        if(!$type) {
            return;
        }

        $this->builder->where('orders.delivery_type', $type);
    }

    public function time_slot($time_slot = null)
    {
        if(!$time_slot) {
            return;
        }

        $order_type = $this->request->get('order_type', null);
        $this->builder->where(function ($query) use ($order_type, $time_slot) {
            $query->where('orders.scheduled_delivery_time_slot_id', $time_slot);
            if (! $order_type) {
                $query->orWhere('orders.delivery_type', Order::DELIVERY_TYPE_FAST);
            }
        });
    }

    public function from_date($fromDate = null)
    {
        $client_order_id = $this->request->get('orderID', null);
        if ($fromDate != null && $client_order_id == null) {
            $this->builder->where(function ($query) use ($fromDate) {
                $query->where([
                    ['orders.created_at', '>=', date('Y-m-d', strtotime($fromDate)).' 00:00:00'],
                    ['orders.delivery_type', '=', Order::DELIVERY_TYPE_FAST],
                ])
                ->orWhere([
                    ['orders.dispatch_at', '>=', date('Y-m-d', strtotime($fromDate)).' 00:00:00'],
                    ['orders.delivery_type', '=', Order::DELIVERY_TYPE_SCHEDULE],
                ]);
            });
        }
    }

    public function to_date($todate = null)
    {
        $client_order_id = $this->request->get('orderID', null);
        
        if ($todate != null && $client_order_id == null) {
            $this->builder->where(function ($query) use ($todate) {
                $query->where([
                    ['orders.created_at', '<=', date('Y-m-d', strtotime($todate)).' 23:59:59'],
                    ['orders.delivery_type', '=', Order::DELIVERY_TYPE_FAST],
                ])
                ->orWhere([
                    ['orders.dispatch_at', '<=', date('Y-m-d', strtotime($todate)).' 23:59:59'],
                    ['orders.delivery_type', '=', Order::DELIVERY_TYPE_SCHEDULE],
                ]);
            });
        }
    }

    public function zone($zone = null)
    {
        if (!$zone) { 
            return;
        }

        $this->builder->where(function ($query) use ($zone) {
            $query->where('orders.zone_id', $zone)
            ->orWhere(function($query) use ($zone) {
                $query->where('orders.zone_id', '=', NULL)
                    ->whereHas('shop', function($query) use($zone) {
                        $query->where('client_shops.zone_id', $zone);
                    });
            });
        });
    }

    public function region($region = null)
    {
        if (!$region) { 
            return;
        }
        $this->builder->where(function ($query) use ($region) {
          $query ->where('orders.region_id', $region)
            ->orWhere(function($query) use ($region) {
                $query->where('orders.region_id', '=', NULL)
                    ->whereHas('shop.region', function($query) use($region) {
                        $query->where('regions.id', $region);
                    });
            });
        });
    }

    public function status($status = null)
    {
        if(!$status) {
            return;
        }
        $status = is_string($status) ? explode(',', $status) : $status;
        if($status && empty(array_diff(is_array($status) ? $status : [$status], [OrderStatus::ORDER_PACKAGE, OrderStatus::ASSIGN_ATTEMPTS]))) {
            if(is_array($status)) {
                $status[] = OrderStatus::NEW_ORDER;
            }

            $this->builder->whereHas('shop', function($query) {
                $query->where('auto_assignable', 1);
            });
        }

        if(is_array($status)) {
            $this->builder->whereIn('orders.status_id', $status);
            return;
        }
        
        $this->builder->where('orders.status_id', $status);
    }

    public function captain($captain = null)
    {
        if(!$captain) {
            return;
        }

        $this->builder->where('orders.captain_id', $captain);
    }

    public function q($search = null) {
        if(!$search) {
            return;
        }

        $this->builder->whereLike(['client_order_id'], $search);
    }
}