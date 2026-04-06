<?php

namespace App\Console\Commands;

use App\Order;
use App\OrderStatus;
use App\Reminder;
use App\Services\Firebase\CloudMessage;
use App\TicketReason;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotifyCaptainToStartRide extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:order-start-ride-reminder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reminds the captain to start ride after accepting/assigning order more than 5 minutes ago';

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
        $orders = Order::query()
            ->select('orders.*')
            ->addSelect('orders.id as id')
            ->with('captain.accessToken', 'captain.user')
            ->leftJoinSub(
                DB::table('order_logs')
                    ->selectRaw('MAX(id) max_id, order_id')
                    ->groupBy('order_id'),
                'last_order_log_id',
                function ($join) {
                    $join->on('orders.id', '=', 'last_order_log_id.order_id');
                }
            )
            ->leftJoin('order_logs', 'order_logs.id', 'last_order_log_id.max_id')
            ->whereIn('orders.status_id', [OrderStatus::ACCEPT, OrderStatus::ASSIGNED_TO])
            ->where('order_logs.created_at', '<', now()->subMinutes(5))
            ->whereHas('captain.currentShift')
            ->orderBy('captain_id')
            ->get();

        $busyCaptains = [];

        foreach ($orders as $order) {
            if (! isset($busyCaptains[$order->captain_id])) {
                $busyCaptains[$order->captain_id] = \App\Order::where('captain_id', $order->captain_id)
                    ->whereIn('status_id', [
                        OrderStatus::START_RIDE,
                        OrderStatus::REACHED_SHOP,
                        OrderStatus::PICKED,
                        OrderStatus::PICKED_UP,
                        OrderStatus::SHIPPED,
                        OrderStatus::REACHED_DESTINATION,
                        OrderStatus::REROUTED,
                        OrderStatus::RELOCATED,
                    ])->exists();
            }

            if ($busyCaptains[$order->captain_id]) {
                continue;
            }

            $this->notify($order);
        }
    }

    public function notify($order)
    {

        $token = $order->captain->accessToken->fb_token ?? null;

        if ($token) {
            $metadata = Reminder::getNotificationMetadata(Reminder::START_RIDE_REMINDER);
            $data = [
                'priority' => 'High',
                'title' => __('app/notifications.start_ride_reminder.title', [], $order->captain->user->language),
                'body' => __('app/notifications.start_ride_reminder.body', [], $order->captain->user->language),
                'reminder_type' => Reminder::START_RIDE_REMINDER, 
                'order_id' => (string) $order->id,
                'client_order_id' => $order->client_order_id,
                'delivery_payment_mode' => $order->delivery_payment_mode,
                "sound" => $metadata['sound'],
                "android_channel_id" => $metadata['android_channel_id'],
                "content_available" => true,
                "mutable_content" => true,
            ];

            try {
                (new CloudMessage($order->captain->firebaseVersion()))
                    ->send([
                        'to' => $token,
                        'notification' => $data,
                        'data' => $data
                    ]);
                
                Log::info("Start Ride reminder sent for Order #{$order->id} to Captain {$order->captain_id}.");
            } catch (\Throwable $e) {
                Log::error("Failed to send Start Ride reminder for Order #{$order->id} to Captain {$order->captain_id}: " . $e->getMessage());
            }
        } else {
            Log::warning("Failed to send Start Ride reminder for Order #{$order->id} to Captain {$order->captain_id}: missing FB token.");
        }
    }
}
