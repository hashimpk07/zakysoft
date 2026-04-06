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

class NotifyCaptainToPickupOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:order-pickup-reminder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'After captain reached shop reminds the captain to pickup the order after waiting more than 5 minutes';

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
            ->with('captain.accessToken', 'captain.user', 'client')
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
            ->where('orders.status_id', OrderStatus::REACHED_SHOP)
            ->where('order_logs.created_at', '<', now()->subMinutes(3))
            ->whereHas('captain.currentShift')
            ->orderBy('captain_id')
            ->get();

        $busyCaptains = [];

        foreach ($orders as $order) {
            if (! isset($busyCaptains[$order->captain_id])) {
                $busyCaptains[$order->captain_id] = \App\Order::where('captain_id', $order->captain_id)
                    ->whereIn('status_id', [
                        OrderStatus::START_RIDE,
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
            $metadata = Reminder::getNotificationMetadata(Reminder::PICKUP_ORDER);
            $data = [
                'priority' => 'High',
                'title' => __('app/notifications.pickup.title', ['awb' => $order->client_order_id], $order->captain->user->language),
                'body' => __('app/notifications.pickup.body', [], $order->captain->user->language),
                'reminder_type' => Reminder::PICKUP_ORDER,
                'order_id' => (string) $order->id,
                'client_order_id' => $order->client_order_id,
                'delivery_payment_mode' => $order->delivery_payment_mode,
                'on_time_payment' => $order->client->on_time_payment,
                'total_amount' => (string) $order->amount,
                "sound" => $metadata['sound'],
                "android_channel_id" => $metadata['android_channel_id'],
                "content_available" => true,
                "mutable_content" => true,
                "ticket_reasons" => TicketReason::active()->select('id', 'reason', 'reason_ar')->get(),
            ];

            try {
                (new CloudMessage($order->captain->firebaseVersion()))
                    ->send([
                        'to' => $token,
                        'notification' => $data,
                        'data' => $data
                    ]);

                Log::info("Pickup reminder sent for Order #{$order->id} to Captain {$order->captain_id}.");
            } catch (\Throwable $e) {
                Log::error("Failed to send Pickup reminder for Order #{$order->id} to Captain {$order->captain_id}: " . $e->getMessage());
            }
        } else {
            Log::warning("Failed to send Pickup reminder for Order #{$order->id} to Captain {$order->captain_id}: missing FB token.");
        }
    }
}