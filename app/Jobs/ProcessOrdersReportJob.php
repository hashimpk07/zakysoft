<?php

namespace App\Jobs;

use App\Actions\UpdateOrderReportAction;
use App\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessOrdersReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The job timeout (24 hours).
     *
     * @var int
     */
    public $timeout = 86400;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Resolve the UpdateOrderReportAction
        // $updateOrderReportAction = app(UpdateOrderReportAction::class);

        // Process orders in chunks
        // DB::table('orders')
        // ->select(
        //     'orders.id',
        //     DB::raw("DATE_FORMAT(orders.created_at, '%Y-%m-%d %H:%i') as created_at"),
        //     'orders.code as order_code',
        //     'orders.delivery_payment_mode',
        //     'clients.id as client_id',
        //     'client.name as client_name',
        //     'client_shops.name as shop_name',
        //     'orders.shopname as shop_id',
        //     'orders.zone_id as zone_id',
        //     'orders.region_id as region_id',
        //     'orders.client_order_id as client_order_id',
        //     'orders.status_id as order_status_id',
        //     'orders.client_order_id',
        //     'order_statuses.name as status',
        //     'captains.id as captain_id',
        //     'captain.name as captain_name',
        //     'captains.iqama_number as iqama_number',
        //     DB::raw("(CASE WHEN order_payments.payment_mode THEN order_payments.payment_mode ELSE orders.delivery_payment_mode END) as order_payment_mode"),
        //     'clients.on_time_payment',
        //     'orders.amount',
        //     'orders.delivery_charge',
        //     'clients.delivery_charge_incl',
        //     'clients.vat_incl',
        //     'orders.vat_rate',
        //     'clients.payment_mode as client_payment_mode',
        //     'clients.fast_delivery_amount',
        //     'clients.scheduled_delivery_amount',
        //     'order_payments.pos_amount',
        //     'order_payments.cash',
        //     'orders.status_id',
        //     'orders.shop_to_delivery_km',
        //     'orders.delivery_date',
        //     'order_delivery_charge.additional_km_earning',
        //     'order_delivery_charge.basic_delivery_charge'
        // )
        // ->leftJoin('clients', 'clients.id', '=', 'orders.client_id')
        // ->leftJoin('captains', 'captains.id', '=', 'orders.captain_id')
        // ->leftJoin('order_statuses', 'order_statuses.id', '=', 'orders.status_id')
        // ->leftJoin('users as captain', 'captain.id', '=', 'captains.user_id')
        // ->leftJoin('users as client', 'client.id', '=', 'clients.user_id')
        // ->leftJoinSub(
        //     DB::table('order_payments')->selectRaw('MAX(id) as max_id, order_id')->groupBy('order_id'),
        //     'last_payment_id',
        //     function ($join) {
        //         $join->on('orders.id', '=', 'last_payment_id.order_id');
        //     }
        // )
        // ->leftJoin('order_payments', 'order_payments.id', '=', 'last_payment_id.max_id')
        // ->leftJoin('client_shops', 'client_shops.id', '=', 'orders.shopname')
        // ->leftJoin('order_delivery_charge', 'order_delivery_charge.order_id', '=', 'orders.id')
        // ->orderBy('orders.updated_at', 'DESC')
        // ->chunk(100000, function ($orders) use ($updateOrderReportAction) {
        //     foreach ($orders as $order) {
        //         $orderModel = Order::find($order->id);
        //         if ($orderModel) {
        //             $updateOrderReportAction->execute($orderModel);
        //         }
        //     }
        // });

        // Resolve the UpdateOrderReportAction
        $updateOrderReportAction = app(UpdateOrderReportAction::class);

        // Initialize counters for tracking progress
        $processedCount = 0;
        $insertedCount = 0;

        // Process orders in chunks
        DB::table('orders') 
        ->whereBetween('orders.created_at', ['2026-03-16 00:00:00', '2026-03-17 23:59:59'])
            ->orderBy('orders.updated_at', 'DESC')
            ->chunk(100000, function ($orders) use ($updateOrderReportAction, &$processedCount, &$insertedCount) {
                foreach ($orders as $order) {
                    $processedCount++;
                    $orderModel = Order::find($order->id);

                    if ($orderModel) {
                        $wasInserted = $updateOrderReportAction->execute($orderModel);

                        // Assuming `execute` returns true if the insertion was successful
                        if ($wasInserted) {
                            $insertedCount++;
                        }
                    }
                }

                // Log progress after processing each chunk
                Log::info('Orders chunk processed', [
                    'processed_count' => $processedCount,
                    'inserted_count' => $insertedCount,
                ]);
            });

        // Final log message after the job completes
        Log::info('Order report job completed', [
            'total_processed' => $processedCount,
            'total_inserted' => $insertedCount,
        ]);
    }

}
