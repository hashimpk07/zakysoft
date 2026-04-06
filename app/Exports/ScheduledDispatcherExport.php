<?php

namespace App\Exports;

use App\DeliveryType;
use App\Filter\OrderFilter;
use App\Order;
use App\OrderStatus;
use App\Request;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;

class ScheduledDispatcherExport implements FromView
{
    use Exportable;

    private $request = [];


    public function __construct($request)
    {
        $this->request = $request;
    }
    /**
     * @return \Illuminate\Support\Collection
     */
    public function view(): View
    {
        $orders = Order::select('orders.code', 'orders.client_order_id', 'orders.amount', 'orders.delivery_charge', 'orders.delivery_date', 'orders.created_at', 'orders.status_id', 'orders.id', 'orders.delivery_time', 'orders.client_id', 'orders.captain_id', 'orders.zone_id', 'orders.region_id', 'orders.shopname', 'orders.delivery_type', 'orders.scheduled_delivery_time_slot_id', 'orders.dispatch_at')
                        ->with([
                            'shop:id,name,express_time,zone_id',
                            'shop.zone:id,name',
                            'shop.region:regions.id,regions.name',
                            'timeSlot',
                            'progress:id,name',
                            'openTicket:id,order_id,type',
                            'openComplaint:id,order_id,type',
                        ])
                        ->where('delivery_type', DeliveryType::SCHEDULES)
                        ->withLastLog()
                        ->withRegionZone()
                        ->withCaptain()
                        ->withClient()
                        ->belongsToMe()
                        ->filter($this->request)
                        ->orderBy('orders.id', 'desc')
                        ->paginate(100)
                        ->withQueryString();

        return view('orders.scheduled_exports', [
            'orders' => $orders,
        ]);
    }
}
