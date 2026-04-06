<?php

namespace App\Console\Commands;

use App\Captain;
use App\Events\OrderAcceptingTimeOuted;
use App\Order;
use App\OrderStatus;
use App\Package;
use Facades\App\Services\OrderStatusLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckWaitingForAcceptOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'order:check-waiting-for-accept';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $waiting_for_accept_timeout_orders = Order::query()
            ->status(OrderStatus::WAITING_FOR_ACCEPTING)
            ->where('delivery_date', '<', now()->subMinutes(Captain::CAPTAIN_ACCEPTING_TIME_IN_MINUTES))
            ->get();

        foreach ($waiting_for_accept_timeout_orders as $key => $order) {
            DB::transaction(function () use ($order) {

                // Package::whereHas('orders', function($query) use ($order) {
                //     $query->where('captain_id', $order->captain_id);
                //     $query->where('order_id', $order->id);
                // })->update(['captain_accepted_at' => now()]);

                $order->status_id = OrderStatus::NEW_ORDER;
                $order->captain_id = null;
                $order->save();
                
                OrderStatusLog::log(OrderStatus::WAITING_TIME_OUT, null, $order->id, null, null, null, config('app.system_user'));
                OrderAcceptingTimeOuted::dispatch($order);
                OrderStatusLog::log(OrderStatus::NEW_ORDER, null, $order->id, null, null, null, config('app.system_user'));
            });
        }
    }
}
