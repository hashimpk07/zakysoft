<?php

namespace App\View\Components;

use App\Captain;
use App\CaptainLocationLog;
use App\ClientShop;
use App\OrderStatus;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\Component;

class ShopLocationMap extends Component
{
    public $shop_id, $order;
    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct($shop, $order)
    {
        $this->shop_id = $shop;
        $order = $order->load('captain.user', 'captain.vehicle.vehicleType', 'captain.location');
        $this->order = $order;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return \Illuminate\View\View|string
     */
    public function render()
    {
        $progressing = !in_array($this->order->status_id, [OrderStatus::DELIVERED, OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED]) ? 1 : 0;
        $captain_assigned = $this->order->captain_id != 0 ? 1 : 0;
        $shop = ClientShop::with('region')->withCount('newOrders')->find($this->shop_id);
        $shop_region = $shop->region->id ?? 0;
        $captains = [];
        if($progressing && !$captain_assigned) {
            $captains = Captain::online()
                ->with('user', 'location', 'vehicle.vehicleType')
                ->withCount('currentOrder')
                ->where('id', '<>', $this->order->captain_id ?? 0)
                ->orderBy('firstname')
                ->get()->map(function($captain) {
                    $captain->setAppends([]);
                    return $captain;
                });
        }

        $accepted_captain_locations = [];
            // DB::table('captain_location_logs as log')
            //     ->select('log.longitude', 'log.latitude', 'log.created_at')
            //     ->join(DB::raw('(
            //         SELECT MIN(id) AS min_id
            //         FROM captain_location_logs
            //         WHERE captain_id = '. ($this->order->captain_id ?? 0) .'
            //         GROUP BY UNIX_TIMESTAMP(created_at) DIV 60
            //     ) as t2'), 'log.id', '=', 't2.min_id')
            //     ->where('log.captain_id', '=', $this->order->captain_id)
            //     ->when($this->order->status_id >= OrderStatus::ACCEPT, function($query) {
            //         $order_status_log = $this->order->logsExecpt->where('status_id', OrderStatus::ACCEPT)->first();
            //         $query->where('log.created_at' , '>=', $order_status_log->created_at ?? $this->order->created_at);
            //     })
            //     ->when(in_array($this->order->status_id, [OrderStatus::DELIVERED, OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED]), function($query) {
            //         $query->where('log.created_at' , '<=', $this->order->logsExecpt->last()->created_at);
            //     })
            //     ->get()
            //     ->map(function($item) {
            //         return [
            //             (float) $item->longitude, (float) $item->latitude
            //         ];
            //     });

        $user_preferred_map_style = Cache::get('user_prefered_map_style-'.auth()->id());
        return view('components.shop-location-map', compact('user_preferred_map_style', 'shop', 'captains', 'accepted_captain_locations', 'progressing', 'captain_assigned'));
    }
}