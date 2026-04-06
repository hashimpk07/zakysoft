<?php
namespace App\Console\Commands;

use App\Order;
use App\OrderStatus;
use App\Reminder;
use App\Sendable;
use App\Services\Firebase\CloudMessage;
use Illuminate\Console\Command;

class PendingOrderReminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */

    protected $signature = 'send:pending-order-reminder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send pending order reminder ';

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
     * @return mixed
     */
    public function handle()
    {
        $pending_orders = Order::with('captain.accessToken', 'captain.user')
                            ->where('status_id', OrderStatus::PENDING)
                            ->get();

        foreach($pending_orders as $order) {
            $this->sendReminder($order);
        }
    }

    public function sendReminder($order)
    {
        $token = $order->captain->accessToken->fb_token ?? null;

        if($token) {
            $metadata = Reminder::getNotificationMetadata(Reminder::PENDING_ORDER_REMINDER);
            $data = [
                'priority' => 'High',
                'title' => __('app/notifications.pending_order_reminder.title', ['order' => $order->client_order_id], $order->captain->user->language ?? 'en'),
                'body' => __('app/notifications.pending_order_reminder.body', ['order' => $order->client_order_id], $order->captain->user->language ?? 'en'),
                'pending_order_id' => $order->id,
                'reminder_type' => Reminder::PENDING_ORDER_REMINDER,
                "sound" => $metadata['sound'],
                "android_channel_id" => $metadata['android_channel_id'],
                "content_available" => true,
                "mutable_content" => true,
            ];

            (new CloudMessage($order->captain->firebaseVersion()))
                ->send([
                    'to' => $token,
                    'notification' => $data,
                    'data' => $data
                ]);
        }
    }
}
