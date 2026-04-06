<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Order;
use App\OrderStatus;
use App\Captain;
use App\Package;
use App\PackageOrder;
use App\PackageDeliveryRequest;
use Carbon\Carbon;
use Facades\App\Services\OrderStatusLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Events\OrderStatusChanged;

class UpdateBulkTestOrderStatuses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:update-statuses
                           {--batch_id= : The batch ID of orders to update}
                           {--count=100 : Number of orders to process per status}
                           {--client_id= : Client ID to filter orders}
                           {--shop_id= : Shop ID to filter orders}
                           {--captain_id= : Captain ID to assign to orders (optional)}
                           {--status= : Specific status to update to (optional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update test orders through their lifecycle in a sequential manner';

    /**
     * Order status sequence for natural lifecycle progression
     */
    protected $statusSequence = [
        OrderStatus::NEW_ORDER,  
        OrderStatus::ORDER_PACKAGE,           // 1. New Order
        OrderStatus::ASSIGN_ATTEMPTS,      // 1. New Order
        OrderStatus::ACCEPT,           // 2. Accepted
        OrderStatus::START_RIDE,       // 3. Start Ride
        OrderStatus::REACHED_SHOP,     // 4. Reached Shop
        OrderStatus::PICKED,           // 5. Order Picked
        OrderStatus::PICKED_UP,        // 6. Order Picked Up
        OrderStatus::SHIPPED,          // 7. Shipped
        OrderStatus::REACHED_DESTINATION, // 8. Reached Destination
        OrderStatus::DELIVERED,        // 9. Delivered
    ];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $batchId = $this->option('batch_id');
        $count = min((int) $this->option('count'), 1000);
        $clientId = $this->option('client_id');
        $shopId = $this->option('shop_id');
        $captainId = $this->option('captain_id');
        $specificStatus = $this->option('status');

        $this->info("Starting order status updates...");

        // Validate captain if provided
        if ($captainId) {
            $captain = Captain::find($captainId);
            if (!$captain) {
                $this->error("Captain with ID {$captainId} not found!");
                return 1;
            }
            $this->info("Using captain: {$captain->user->name}");
        } else {
            // Find an active captain to assign orders to
            $captain = Captain::with('user')->where('status', 'active')->first();
            if (!$captain) {
                $this->error("No active captains found in the system!");
                return 1;
            }
            $captainId = $captain->id;
            $this->info("Using default captain: {$captain->user->name}");
        }

        // Build the base query for finding orders
        $query = Order::query()
            ->where('status_id', '!=', OrderStatus::DELIVERED) // Exclude already delivered orders
            ->where(function ($q) {
                $q->where('client_order_id', 'like', 'TEST_%')
                    ->orWhere('client_order_id', 'like', 'BULK_%');
            })
            ->orderBy('status_id', 'asc')
            ->orderBy('updated_at', 'asc');

        // Apply filters if provided
        if ($batchId) {
            $query->where('client_order_id', 'like', "%{$batchId}%");
            $this->info("Filtering by batch ID: {$batchId}");
        }

        if ($clientId) {
            $query->where('client_id', $clientId);
            $this->info("Filtering by client ID: {$clientId}");
        }

        if ($shopId) {
            $query->where('shopname', $shopId);
            $this->info("Filtering by shop ID: {$shopId}");
        }

        // If a specific status is requested, only update orders to that status
        if ($specificStatus) {
            $statusIndex = array_search($specificStatus, $this->statusSequence);
            if ($statusIndex === false) {
                $this->error("Invalid status ID: {$specificStatus}");
                return 1;
            }

            // Find the previous status in the sequence
            $previousStatus = $statusIndex > 0 ? $this->statusSequence[$statusIndex - 1] : null;

            if ($previousStatus) {
                $query->where('status_id', $previousStatus);
                $this->info("Updating orders from {$previousStatus} to {$specificStatus}");

                $orders = $query->take($count)->get();
                if ($orders->isEmpty()) {
                    $this->info("No orders found with status {$previousStatus}");
                    return 0;
                }

                $this->updateOrdersToStatus($orders, $specificStatus, $captainId);
                return 0;
            }
        }

        // Process each status in sequence (skipping the first status which is NEW_ORDER)
        for ($i = 1; $i < count($this->statusSequence); $i++) {
            $currentStatus = $this->statusSequence[$i];
            $previousStatus = $this->statusSequence[$i - 1];

            $this->info("Processing transition: {$previousStatus} → {$currentStatus}");

            // Get orders that are in the previous status
            $ordersToUpdate = Order::where('status_id', $previousStatus)
                ->where(function ($q) {
                    $q->where('client_order_id', 'like', 'TEST_%')
                        ->orWhere('client_order_id', 'like', 'BULK_%');
                });

            // Apply the same filters
            if ($batchId) {
                $ordersToUpdate->where('client_order_id', 'like', "%{$batchId}%");
            }

            if ($clientId) {
                $ordersToUpdate->where('client_id', $clientId);
            }

            if ($shopId) {
                $ordersToUpdate->where('shopname', $shopId);
            }

            $orders = $ordersToUpdate->take($count)->get();

            if ($orders->isEmpty()) {
                $this->info("No orders found with status {$previousStatus}");
                continue;
            }

            $this->updateOrdersToStatus($orders, $currentStatus, $captainId);

            // Add a small delay between status transitions
            $this->info("Waiting 5 seconds before next transition...");
            sleep(5);
        }

        $this->info("Order status update process completed!");
        return 0;
    }

    /**
     * Update a batch of orders to a specific status
     */
    protected function updateOrdersToStatus($orders, $newStatusId, $captainId)
    {
        $count = $orders->count();
        $this->info("Updating {$count} orders to status {$newStatusId}");

        DB::beginTransaction();
        try {
            foreach ($orders as $order) {
                $this->info("Updating order {$order->client_order_id} from {$order->status_id} to {$newStatusId}");

                // Handle special case for accepting orders
                if ($newStatusId == OrderStatus::ACCEPT && $order->captain_id != $captainId) {
                    $this->assignCaptainToOrder($order, $captainId);
                } else {
                    // Regular status update
                    $order->status_id = $newStatusId;

                    // If this is the delivery status, set delivery date
                    if ($newStatusId == OrderStatus::DELIVERED) {
                        $order->delivery_date = now();
                    }

                    $order->save();

                    // Log the status change
                    OrderStatusLog::log($newStatusId, $captainId, $order->id, null, 'Automated test status update');

                    // Dispatch event
                    OrderStatusChanged::dispatch($order);
                }
            }

            DB::commit();
            $this->info("Successfully updated {$count} orders to status {$newStatusId}");

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Error updating orders: " . $e->getMessage());
            Log::error('Error in bulk status update: ' . $e->getMessage());
        }
    }

    /**
     * Assign a captain to an order (similar to updateCaptain method in OrderController)
     */
    protected function assignCaptainToOrder($order, $captainId)
    {
        $captainData = Captain::find($captainId);

        // Remove any existing package order
        if ($existingPackage = PackageOrder::where('order_id', $order->id)->first()) {
            $existingPackage->delete();
        }

        // Create a new package
        $package = Package::create([
            'captain_accepted_at' => now(),
            'captain_id' => $captainData->id,
            'client_shop_id' => $order->shopname,
        ]);

        // Create package order
        PackageOrder::updateOrInsert(
            [
                'order_id' => $order->id,
            ],
            [
                'priority' => 1,
                'package_id' => $package->id,
            ]
        );

        // Create delivery request
        PackageDeliveryRequest::updateOrInsert(
            [
                'package_id' => $package->id,
                'captain_id' => $captainData->id,
            ],
            [
                'sended_at' => now(),
            ]
        );

        // Update order status
        $order->status_id = OrderStatus::ACCEPT;
        $order->captain_id = $captainId;
        $order->save();

        // Log status change
        OrderStatusLog::log(OrderStatus::ACCEPT, $captainId, $order->id, null, 'Automated test status update - Captain assigned');

        // Dispatch event
        OrderStatusChanged::dispatch($order);

        $this->info("Assigned captain {$captainData->user->name} to order {$order->client_order_id}");
    }
}
