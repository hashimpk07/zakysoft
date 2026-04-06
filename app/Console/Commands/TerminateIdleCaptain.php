<?php

namespace App\Console\Commands;

use App\Captain;
use App\Reminder;
use App\Services\Firebase\CloudMessage;
use Illuminate\Console\Command;

class TerminateIdleCaptain extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */

    protected $signature = 'captain:terminate-idle';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Terminate idle captain';

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
        Captain::select('id', 'user_id')
            ->with('currentShift', 'accessToken', 'user')
            ->online()
            ->idle()
            ->chunk(20, function ($captains) {
                foreach ($captains as $captain) {
                    if($captain->currentShift && !$captain->currentShift->isStartedAtAfter(1800) || $captain->hasInProgressingOrders()) {
                        continue;
                    }

                    $this->terminate($captain);
                }
            });
    }

    public function terminate(Captain $captain) {
        $os = null;
        $version = null;
        $device = $captain->device;
        $device_array = explode(',', $device);
        [$os, $version] = explode(':', $device_array[2]);

        if(!$captain->currentShift || $os == "Android" && $version == 11) {
            return;
        }

        $captain->currentShift->terminate();

        // send notification to captain
        $token = $captain->accessToken->fb_token ?? null;
        if(!$token) 
            return;

        $metadata = Reminder::getNotificationMetadata(Reminder::SHIFT_CLOSED);
        $data = [
            'priority' => 'High',
            'reminder_type' => Reminder::SHIFT_CLOSED, 
            'title' => __('app/notifications.shift_terminate.title', [], $captain->user->language ?? 'en'),
            'body' => __('app/notifications.shift_terminate.body', [], $captain->user->language ?? 'en'),
            "sound" => $metadata['sound'],
            "android_channel_id" => $metadata['android_channel_id'],
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
