<?php

namespace App\Exports;

use App\Filter\OrderFilter;
use App\Order;
use App\OrderStatus;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;

class DispatcherExport implements FromView
{
    use Exportable;

    private $shop,$clientID,$from,$to, $status, $request;

    public function __construct(string $shop=null, string $clientID=null, string $from=null, string $to=null, int $status=null, OrderFilter $request)
    {
        $this->shop = $shop;
        $this->clientID = $clientID;
        $this->from = $from;
        $this->to = $to;
        $this->status = $status;
        $this->request = $request;
    }
    /**
     * @return \Illuminate\Support\Collection
     */
    public function view(): View
    {
        $status = $this->status ? $this->status : request()->get('status');

        $orders = Order::select('code', 'client_order_id', 'amount', 'delivery_charge', 'delivery_date', 'created_at', 'status_id', 'id', 'delivery_time', 'client_id', 'captain_id', 'zone_id', 'region_id', 'shopname', 'delivery_type', 'scheduled_delivery_time_slot_id')
                    ->with([
                        'shop:id,name,express_time,zone_id',
                        'shop.zone:id,name',
                        'shop.region:regions.id,regions.name',
                        'timeSlot',
                        'progress:id,name',
                        'openTicket:id,order_id'
                    ])
                    ->when(
                        $status && empty(array_diff(is_array($status) ? $status : [$status], [OrderStatus::ORDER_PACKAGE, OrderStatus::ASSIGN_ATTEMPTS])),
                        function($query) {
                            $query->with('package.package');
                        }
                    )
                    ->when(
                        $status && empty(array_diff(is_array($status) ? $status : [$status], [OrderStatus::NEW_ORDER])),
                        function($query) {
                            $query->whereHas('shop', function ($query) {
                                $query->where('auto_assignable', 0);
                            });
                        }
                    )
                    ->withLastLog()
                    ->withRegionZone()
                    ->withCaptain()
                    ->withClient()
                    ->belongsToMe()
                    ->filter($this->request)
                    ->orderBy('id', 'desc')
                    ->get();

        return view('orders.exports', [
            'orders' => $orders,
        ]);
    }
}
