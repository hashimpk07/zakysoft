<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Order;
use App\OrderStatus;
use App\Events\EnableManualAssign;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class EnableManualAssignCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:enable-manual-assign-command';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enable manual assign based on time limit';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = now();

        $orders = Order::whereIn('status',[OrderStatus::ORDER_PACKAGE, OrderStatus::ASSIGN_ATTEMPTS, OrderStatus::NEW_ORDER])
            ->whereBetween('created_at', [now()->subMinutes(10), now()])
            ->whereHas('shop.dispatchRuleForExpress', function ($q) {
                $q->whereNotNull('manual_time_limit');
            })
            ->with('shop.dispatchRuleForExpress')
            ->limit(10)
            ->get();

        // Log::info('Orders with dispatch rule express: ', [$orders]);

        foreach ($orders as $order) {
            $createdDiff = $order->created_at->diffInMinutes($now);

            if ($createdDiff >= $order->dispatchRuleForExpress?->manual_time_limit || $order->captain_id) {
                event(new EnableManualAssign($order->id));
            }
        }

        $this->info('Checked manual assign time limits.');
    }
}
