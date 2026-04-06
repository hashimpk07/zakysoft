<?php

namespace App\View\Components;

use App\Package;
use Illuminate\View\Component;

class Reminder extends Component
{

    public $reminders = [];
    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct()
    {
        $reminding_auto_assign_order = Package::query()
            ->with(['directOrders' => function($query) {
                $query->withLastLog();
            }], 'shop')
            ->whereHas('deliveryRequests', function($query) {
                $query->whereRaw("TIMESTAMPDIFF(SECOND, created_at, now()) > 60");
            })
            ->whereHas('directOrders', function($query){
                $query->readyToDispatch();
                $query->autoAssignable();
                $query->belongsToMe();
            })
            ->doesntHave('captain')
            ->get();
        
        $orders = $reminding_auto_assign_order->pluck('directOrders')->flatten();

        foreach ($orders as $key => $order) {
            $this->reminders[] = "<a href='" . route('orders.show', $order) . "'><span class='bn-seperator bn-news-dot'></span> #". $order->client_order_id ." ". ($order->lastLog->note ?? "Pending to assign order more than 1 minute") ."</a>";
        }
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return \Illuminate\View\View|string
     */
    public function render()
    {
        return view('components.reminder', ['reminders' => $this->reminders]);
    }
}
