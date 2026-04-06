<?php

namespace App\Console\Commands;

use App\Captain;
use App\Reminder;
use App\Services\Firebase\CloudMessage;
use Illuminate\Console\Command;

class CaptainShiftReminder extends Command
{
    protected $signature = 'send:captain-shift-reminder';
    protected $description = 'Remind captain working hours is finished';

    protected $working_hours = 15 * 60 * 60;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        Captain::query()
            ->with('accessToken', 'user')
            ->shiftStaredBefore(now()->subSeconds($this->working_hours))
            ->onlineFree()
            ->hasReminderPaused(Reminder::SHIFT_CLOSE, now())
            ->get()
            ->each(function ($captain) {
                $this->sendReminder($captain);
            });

        return 0;
    }


    public function sendReminder($captain)
    {
        $token = $captain->accessToken->fb_token ?? null;

        if ($token) {
            $title = __("app/notifications.shift_reminder.title", ['hours' => (($this->working_hours) / (60 * 60))], $captain->user->language ?? 'en');
            $body = __("app/notifications.shift_reminder.body", ['hours' => (($this->working_hours) / (60 * 60))], $captain->user->language ?? 'en');
            
            $metadata = Reminder::getNotificationMetadata(Reminder::SHIFT_CLOSE);

            $data = [
                'priority' => 'High',
                'title' => $title,
                'body' => $body,
                'reminder_type' => Reminder::SHIFT_CLOSE,
                "sound" => $metadata['sound'],
                "android_channel_id" => $metadata['android_channel_id'],
                "content_available" => true,
                "mutable_content" => true,
            ];

            try {
                (new CloudMessage($captain->firebaseVersion()))
                    ->send([
                        'to' => $token,
                        'notification' => $data,
                        'data' => $data
                    ]);

                $this->storeReminder(
                    $captain,
                    $title,
                    $body
                );
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('CaptainShiftReminder failed to send push notification to captain ' . $captain->id . ': ' . $e->getMessage());
            }
        }
    }

    public function storeReminder($captain, $title, $body)
    {
        $captain->reminders()->create([
            'reminder_type' => Reminder::SHIFT_CLOSE,
            'title' => $title,
            'body' => $body,
        ]);
    }
}
