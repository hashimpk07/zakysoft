<?php

namespace App\Console\Commands;

use App\Events\OrderAddressChanged;
use App\Order;
use App\OrderStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FireOrderAddressChangedCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:fire-address-events 
                            {--chunk=50 : Number of orders to process per chunk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fire OrderAddressChanged event for multiple orders in chunks';


    /**
     * Execute the console command.
     */
    public function handle()
    {
        $chunkSize = (int) $this->option('chunk') ?? 50;
        $rateLimitPerMinute = 60; // Mapbox limit
        $delayBetweenChunks = ceil($chunkSize / $rateLimitPerMinute * 60);

        $this->info("Starting to dispatch OrderAddressChanged events in chunks of $chunkSize...");

        Order::whereBetween('created_at', ['2025-07-01 00:00:00', '2025-07-10 23:59:59'])
            ->where('shop_to_delivery_km', '<=', -0.001)
            ->chunkById($chunkSize, function ($orders) use ($delayBetweenChunks) {
                foreach ($orders as $order) {
                    try {
                        event(new OrderAddressChanged($order));
                        Log::channel('order_address_changed')->info("Fired event for Order ID: {$order->id}");
                        $this->info("Fired event for Order ID: {$order->id}");
                    } catch (\Throwable $e) {
                        Log::channel('order_address_changed')->error("Failed to fire event for Order ID: {$order->id}", [
                            'error' => $e->getMessage(),
                        ]);
                        $this->error("Error firing event for Order ID: {$order->id}");
                    }
                }
                $this->info("Sleeping {$delayBetweenChunks} seconds to respect rate limits...");
                sleep($delayBetweenChunks);
            });

        $this->info('All events have been dispatched.');
    }

}
