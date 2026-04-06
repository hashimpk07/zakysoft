<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CreateBulkTestOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:create-bulk
                            {--count=1000 : Number of orders to create}
                            {--client_id= : Client ID for the test orders}
                            {--shop_id= : Shop ID for the test orders}
                            {--batch_id= : Custom batch ID (optional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create bulk test orders for sandbox/testing environment';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $count = $this->option('count');
        $client_id = $this->option('client_id');
        $shop_id = $this->option('shop_id');
        $batch_id = $this->option('batch_id') ?: 'cmd_' . now()->format('YmdHis');

        // Validate required options
        if (!$client_id || !$shop_id) {
            $this->error('Client ID and Shop ID are required!');
            return 1;
        }

        $this->info("Creating {$count} test orders for client {$client_id} and shop {$shop_id}");
        $this->info("Batch ID: {$batch_id}");

        // Call the controller method directly for better performance
        $controller = app()->make(\App\Http\Controllers\OrderController::class);
        $request = new \Illuminate\Http\Request();
        $request->merge([
            'count' => $count,
            'client_id' => $client_id,
            'shop_id' => $shop_id,
            'batch_id' => $batch_id,
        ]);

        // Set API key in header
        $request->headers->set('X-API-KEY', env('BULK_ORDER_API_KEY'));

        $response = $controller->createBulkTestOrders($request);
        $data = json_decode($response->getContent(), true);

        if ($data['status'] === 'success') {
            $this->info("Successfully created {$data['count']} orders with batch ID {$data['batch_id']}");
            return 0;
        } else {
            $this->error("Failed to create orders: {$data['message']}");
            return 1;
        }
    }
}
