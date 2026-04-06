<?php

namespace App\Console\Commands;

use App\Captain;
use App\Order;
use App\OrderStatus;
use App\Services\StreamlineCacheService;
use App\Cache\StreamlineOrder;
use App\Cache\StreamlineCaptain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncStreamlineCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'streamline:sync-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Syncs existing orders and captains to Streamline Caches';

    protected $streamlineService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(StreamlineCacheService $streamlineService)
    {
        parent::__construct();
        $this->streamlineService = $streamlineService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting Streamline Cache Sync...');

        $this->info('Flushing existing cache...');
        (new StreamlineOrder())->flush();
        (new StreamlineCaptain())->flush();
        $this->info('Cache flushed.');

        // 1. Sync Active Orders
        $this->info('Syncing Active Orders...');
        
        $activeStatuses = array_diff(
            OrderStatus::getAllStatuses(), 
            OrderStatus::FINISHED
        );
        
        $ordersQuery = Order::with(['openComplaint', 'client.user', 'shop.brand', 'shop.zone.region', 'assignAttempts', 'captain.user', 'captain.captainThirdParty', 'progress'])->whereIn('status_id', $activeStatuses);
        $bar = $this->output->createProgressBar($ordersQuery->count());
        $bar->start();

        $ordersQuery->chunk(100, function ($orders) use ($bar) {
            foreach ($orders as $order) {
                try {
                    $this->streamlineService->updateOrder($order);
                } catch (\Exception $e) {
                    $this->error("Failed to sync order {$order->id}: " . $e->getMessage());
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Active Orders Synced.');

        // 2. Sync Active Captains
        $this->info('Syncing Captains...');
        // We sync ALL active captains (Online OR have Active Order)
        $now = now();
        if ($now->format('H:i:s') < '06:00:00') {
            $businessDayStart = $now->copy()->subDay()->setTime(6, 0, 0);
            $businessDayEnd = $now->copy()->setTime(5, 59, 59);
        } else {
            $businessDayStart = $now->copy()->setTime(6, 0, 0);
            $businessDayEnd = $now->copy()->addDay()->setTime(5, 59, 59);
        }

        $captainsQuery = Captain::active()
            ->with(['captainThirdParty', 'user', 'currentShift', 'currentOrder.client.user', 'currentOrder.shop', 'location', 'regions', 'employmentType', 'company'])
            ->withCount(['deliveredOrders as delivered_orders_count' => function ($query) use ($businessDayStart, $businessDayEnd) {
                $query->whereBetween('delivery_date', [$businessDayStart, $businessDayEnd]);
            }])
            ->where(function($q) {
                 $q->has('currentShift')->orWhereHas('currentOrder');
        }); 
        
        $barCapt = $this->output->createProgressBar($captainsQuery->count());
        $barCapt->start();

        $captainsQuery->chunk(100, function ($captains) use ($barCapt) {
            foreach ($captains as $captain) {
                $barCapt->advance();

                try {
                    $this->streamlineService->updateCaptain($captain);
                } catch (\Exception $e) {
                    Log::error("Failed to sync captain {$captain->id}: " . $e->getMessage());
                }
            }
        });

        $barCapt->finish();
        $this->newLine();
        $this->info('Captains Synced.');

        $this->info('Streamline Cache Sync Completed!');
        return 0;
    }
}
