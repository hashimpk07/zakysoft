<?php

namespace App\Console\Commands;

use App\Order;
use App\OrderStatus;
use App\Reminder;
use App\Services\Firebase\CloudMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotifyCaptainToCompleteDelivery extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:order-complete-delivery-reminder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reminds the captain to complete delivery after reaching destination more than 5 minutes ago';

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
            ->where('orders.status_id', OrderStatus::REACHED_DESTINATION)
            ->where('order_logs.created_at', '<', now()->subMinutes(5))
            ->whereHas('captain.currentShift')
            ->orderBy('captain_id')
            ->get();

        $sended_captains = [];
        foreach ($orders as $key => $order) {
            if (!in_array($order->captain_id, $sended_captains)) {
                $sended_captains[] = $order->captain_id;
                $this->notify($order);
            }
        }
    }

    public function notify($order)
    {

        $token = $order->captain->accessToken->fb_token ?? null;

        if ($token) {
            $metadata = Reminder::getNotificationMetadata(Reminder::COMPLETE_DELIVERY_REMINDER);
            $data = [
                'priority' => 'High',
                'title' => __('app/notifications.complete_delivery_reminder.title', [], $order->captain->user->language),
                'body' => __('app/notifications.complete_delivery_reminder.body', [], $order->captain->user->language),
                'reminder_type' => Reminder::COMPLETE_DELIVERY_REMINDER,
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

                Log::info("Complete delivery reminder sent for Order #{$order->id} to Captain {$order->captain_id}.");
            } catch (\Throwable $e) {
                Log::error("Failed to send Complete delivery reminder for Order #{$order->id} to Captain {$order->captain_id}: " . $e->getMessage());
            }
        } else {
            Log::warning("Failed to send Complete delivery reminder for Order #{$order->id} to Captain {$order->captain_id}: missing FB token.");
        }
    }
}
