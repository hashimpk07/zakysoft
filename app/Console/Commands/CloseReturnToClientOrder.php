<?php

namespace App\Console\Commands;

use App\CancellationReason;
use App\Events\OrderStatusChanged;
use App\Order;
use App\OrderLog;
use App\OrderStatus;
use App\User;
use Facades\App\Services\OrderStatusLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CloseReturnToClientOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'close:return-to-client-orders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Closing the orders captain put the return to client after 20 minutes ';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Order::query()
        ->select('orders.*')
        ->addSelect('orders.id as id')
        ->withLastLog()
        ->with('progress')
        ->leftJoin('order_logs', function ($join) {
            $join->on('orders.id', '=', 'order_logs.order_id')
                ->whereRaw('order_logs.id = (
                    SELECT MAX(id) FROM order_logs 
                    WHERE order_id = orders.id
                )');
        })
        ->where(function ($query) {
            $query->where('orders.status_id', OrderStatus::RETURN_TO_CLIENT)
                ->orWhere(function ($innerQuery) {
                    $innerQuery->where('orders.status_id', OrderStatus::REQUEST_FOR_CANCEL)
                        ->where('order_logs.reason_id', '<>', CancellationReason::DELAYED_BY_CAPTAIN);
                });
        })
        ->where('order_logs.created_at', '<', now()->subMinutes(10))
        ->get()
        ->each(function ($order) {
            $this->closeOrder($order);
        });

        return 0;
    }

    public function closeOrder(Order $order)
    {
        try {
            DB::connection('mysql::write')->beginTransaction();
                $status_id = OrderStatus::CLIENT_RETURN_ACCEPTED;
                if($order->progress->id === OrderStatus::REQUEST_FOR_CANCEL) {
                    $status_id = OrderStatus::CANCEL;
                }

                $content = 'Order No '. $order->client_order_id.' Status changed from '.$order->progress->name.' to Client Return Accepted';
                $reason = optional($order->lastLog)->note ?? null; 
                $reasonId = optional($order->lastLog)->reason_id ?? null; 
                // $reason = "";
                
                $system_user = config('app.system_user');
                OrderStatusLog::logs('Order', $content, $system_user);
                $order->update([
                    'status_id' => $status_id,
                    'created_by'=> $system_user
                ]);
                OrderStatusLog::log($status_id, null, $order->id, $reasonId, $reason, null, $system_user);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }

        OrderStatusChanged::dispatch($order);
    }
}