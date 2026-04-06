<?php

namespace App\Console\Commands;

use App\Captain;
use App\Reminder;
use App\Services\Firebase\CloudMessage;
use Illuminate\Console\Command;

class NotifyIdleCaptain extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */

  protected $signature = 'captain:idle-notify';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Sending ideal captain notification idle captain';

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
    $captains = Captain::with('currentShift', 'accessToken', 'user')
      ->online()
      ->where(function ($q) {
        $q->whereHas('location', function ($l) {
          $l->where('last_updated_at', '<', now()->subMinutes(10));
        })->orWhereDoesntHave('location');
      })
      ->get();

    foreach ($captains as $key => $captain) {
      $reminder = $captain->reminders()->where('reminder_type', Reminder::LOCATION_NOT_GETTING)->latest()->first();
      if (!$reminder) {
        $this->sendReminder($captain);
      }

      if ($reminder && $reminder->isPaused()) {
        continue;
      }

      if ($reminder) {
        $reminder->update([
          'pause_upto' => now()->addMinutes(5)
        ]);
      }


      // send notification to captain
      $token = $captain->accessToken->fb_token ?? null;
      if (!$token)
        continue;

      $metadata = Reminder::getNotificationMetadata(Reminder::LOCATION_NOT_GETTING);

      $data = [
        'priority' => 'High',
        'reminder_type' => Reminder::LOCATION_NOT_GETTING,
        'title' => __('app/notifications.idle.title', [], $captain->user->language ?? 'en'),
        'body' => __('app/notifications.idle.body', [], $captain->user->language ?? 'en'),
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

  public function sendReminder($captain)
  {
    return $captain->reminders()->create([
      "reminder_type" => Reminder::LOCATION_NOT_GETTING,
      "title" => "app/notifications.idle.title",
      "body" => "app/notifications.idle.body",
      "pause_upto" => now()->addMinutes(10),
    ]);
  }
}
