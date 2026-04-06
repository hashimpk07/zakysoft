<?php

namespace App\Console\Commands;

use App\Captain;
use App\Reminder;
use App\Services\Firebase\CloudMessage;
use Illuminate\Console\Command;

class CloseCaptainShift extends Command
{

    protected $signature = 'close:captain-shift';
    protected $description = 'Close captain shift when no response from captain';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        Captain::query()
            ->with('currentShift', 'accessToken', 'user')
            ->onlineFree()
            ->hasReminderPaused(Reminder::SHIFT_CLOSE, now())
            ->whereHas(
                'reminder',
                function($query) {
                    $query
                        ->where('created_at', '<', now()->subMinutes(5))
                        ->whereNull('pause_upto');
                }
            )
            ->whereHas('currentShift', function($query) {
                $query->where('shift_start', '<', now()->subMinutes(10));
            })
            ->each(function($captain) {
                $this->closeCaptainShift($captain);
                $captain->reminders()->delete();
            });
        return 0;
    }

    public function closeCaptainShift($captain)
    {
        $token = $captain->accessToken->fb_token ?? null;
        
        if($token) {
            $captain->currentShift->terminate();
            $metadata = Reminder::getNotificationMetadata(Reminder::SHIFT_CLOSED);
            $data = [
                'priority' => 'High',
                'sound' => $metadata['sound'],
                'android_channel_id' => $metadata['android_channel_id'],
                'reminder_type' => Reminder::SHIFT_CLOSED, 
                'title' => __('app/notifications.shift_close.title', [], $captain->user->language ?? 'en'),
                'body' => __('app/notifications.shift_close.body', [], $captain->user->language ?? 'en'),
                "content_available" => true,
                "mutable_content" => true,
            ];

            (new CloudMessage($captain->firebaseVersion()))
                ->send([
                    'to' => $token,
                    'notification' => $data,
                    'data' => $data
                ]);
        }
    }
}
