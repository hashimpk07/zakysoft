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

class FastCloseReturnToClientOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fast-close:return-to-client-orders';

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
        $clientIds = config('app.auto_cancel_client_id');

        if(empty($clientIds)) {
            return 1;
        }

        Order::query()
        ->select('orders.*')
        ->addSelect('orders.id as id')
        ->with('progress')
        ->withLastLog()
        ->where('orders.status_id', OrderStatus::REQUEST_FOR_CANCEL)
        ->whereIn('orders.client_id', $clientIds)      
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

                // if(in_array($order->client_id, config('app.auto_cancel_client_id'))) {
                //     $reason = "Cancelled by Client";
                // }

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