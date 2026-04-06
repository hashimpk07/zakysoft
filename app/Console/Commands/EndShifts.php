<?php

namespace App\Console\Commands;

use App\Captain;
use App\Reminder;
use App\Services\Firebase\CloudMessage;
use Illuminate\Console\Command;

class EndShifts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:end-shifts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'End shifts at the end of business day for online and free captains';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $captains = Captain::query()
            ->with(['currentShift', 'accessToken', 'user'])
            ->onlineFree()
            ->get();

        $affectedRows = 0;

        foreach ($captains as $captain) {
            if ($captain->currentShift) {
                // Update the shift_end field, which fires the necessary events
                $captain->currentShift->terminate();
                $affectedRows++;

                // Send shift closure notification
                $this->sendNotification($captain);
            }
        }

        $this->info("Ended $affectedRows shifts at the end of business day.");
    }

    private function sendNotification($captain)
    {
        $token = $captain->accessToken->fb_token ?? null;

        if ($token) {
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

            try {
                (new CloudMessage($captain->firebaseVersion()))
                    ->send([
                        'to' => $token,
                        'notification' => $data,
                        'data' => $data
                    ]);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('EndShifts failed to send push notification to captain ' . $captain->id . ': ' . $e->getMessage());
            }
        }
    }
}
