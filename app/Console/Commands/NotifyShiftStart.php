<?php

namespace App\Console\Commands;

use App\Captain;
use App\Reminder;
use App\ShiftRuleSetting;
use App\Services\Firebase\CloudMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class NotifyShiftStart extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'captain:shift-notify';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send notifications to captains before their shift starts';

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
        $now = Carbon::now();
        $currentDay = $now->dayOfWeek; // 0=Sunday, 1=Monday, etc.
        $currentTime = $now->format('H:i:s');

        // Log::info('NotifyShiftStart: Starting shift notification process', [
        //     'current_time' => $currentTime,
        //     'current_day' => $currentDay,
        //     'day_name' => $now->format('l')
        // ]);

        // Get all shift rule settings for today
        $todayShiftSettings = ShiftRuleSetting::where('day', $currentDay)
            ->with(['shiftRule.captains.user', 'shiftRule.captains.accessToken', 'shiftRule.captains.currentShift'])
            ->get();

        // Log::info('NotifyShiftStart: Found shift settings for today', [
        //     'count' => $todayShiftSettings->count(),
        //     'day' => $currentDay
        // ]);

        foreach ($todayShiftSettings as $shiftSetting) {
            $this->processShiftSetting($shiftSetting, $now, $currentTime);
        }

        $this->info('Shift notifications processed successfully.');
        // Log::info('NotifyShiftStart: Completed shift notification process');
    }

    /**
     * Process a single shift setting and notify captains if needed
     */
    private function processShiftSetting($shiftSetting, $now, $currentTime)
    {
        // Log::info('NotifyShiftStart: Processing shift setting', [
        //     'shift_rule_id' => $shiftSetting->shift_rule_id,
        //     'day' => $shiftSetting->day,
        //     'shift_a_start' => $shiftSetting->shift_a_start,
        //     'shift_a_end' => $shiftSetting->shift_a_end,
        //     'shift_b_start' => $shiftSetting->shift_b_start,
        //     'shift_b_end' => $shiftSetting->shift_b_end
        // ]);

        $shifts = [
            'A' => ['start' => $shiftSetting->shift_a_start, 'end' => $shiftSetting->shift_a_end],
            'B' => ['start' => $shiftSetting->shift_b_start, 'end' => $shiftSetting->shift_b_end],
        ];

        foreach ($shifts as $shiftType => $shift) {
            if (!$shift['start'] || !$shift['end']) {
                // Log::info('NotifyShiftStart: Skipping shift - times not set', [
                //     'shift_type' => $shiftType,
                //     'start' => $shift['start'],
                //     'end' => $shift['end']
                // ]);
                continue; // Skip if shift times are not set
            }

            $this->checkAndNotifyForShift($shiftSetting, $shift, $shiftType, $now, $currentTime);
        }
    }

    /**
     * Check if notification should be sent for a specific shift
     */
    private function checkAndNotifyForShift($shiftSetting, $shift, $shiftType, $now, $currentTime)
    {
        $shiftStart = Carbon::parse($shift['start']);
        $notifyTime = $shiftStart->copy()->subMinutes(10); // 10 minutes before shift start

        // Log::info('NotifyShiftStart: Checking notification timing', [
        //     'shift_type' => $shiftType,
        //     'shift_start' => $shiftStart->format('H:i:s'),
        //     'notify_time' => $notifyTime->format('H:i:s'),
        //     'current_time' => $now->format('H:i:s')
        // ]);

        // Check if we should notify now (within 1 minute window)
        $shouldNotify = $now->between(
            $notifyTime->copy()->subSeconds(30),
            $notifyTime->copy()->addSeconds(30)
        );

        if (!$shouldNotify) {
            // Log::info('NotifyShiftStart: Not time to notify yet', [
            //     'shift_type' => $shiftType,
            //     'should_notify' => false,
            //     'notify_window_start' => $notifyTime->copy()->subSeconds(30)->format('H:i:s'),
            //     'notify_window_end' => $notifyTime->copy()->addSeconds(30)->format('H:i:s')
            // ]);
            return;
        }

        // Log::info('NotifyShiftStart: Within notification window', [
        //     'shift_type' => $shiftType,
        //     'should_notify' => true
        // ]);

        // Get captains assigned to this shift rule
        $captains = $shiftSetting->shiftRule->captains()
            ->with(['user', 'accessToken', 'currentShift'])
            ->where('status', Captain::STATUS_ACTIVE)
            ->get();

        // Log::info('NotifyShiftStart: Found captains for shift rule', [
        //     'shift_rule_id' => $shiftSetting->shift_rule_id,
        //     'captain_count' => $captains->count()
        // ]);

        foreach ($captains as $captain) {
            $this->notifyCaptain($captain, $shift, $shiftType, $shiftStart);
        }
    }

    /**
     * Send notification to captain
     */
    private function notifyCaptain($captain, $shift, $shiftType, $shiftStart)
    {
        // Log::info('NotifyShiftStart: Processing captain notification', [
        //     'captain_id' => $captain->id,
        //     'captain_name' => $captain->user->name ?? 'Unknown',
        //     'shift_type' => $shiftType,
        //     'shift_start' => $shiftStart->format('H:i')
        // ]);

        // Check if captain is already online
        $isOnline = $captain->currentShift()->exists();

        if ($isOnline) {
            // Log::info('NotifyShiftStart: Captain already online, skipping', [
            //     'captain_id' => $captain->id,
            //     'captain_name' => $captain->user->name ?? 'Unknown'
            // ]);
            $this->info("Captain {$captain->user->name} is already online, skipping notification.");
            return;
        }

        // Check for existing reminder to avoid spam (within the last 5 minutes)
        $existingReminder = $captain->reminders()
            ->where('reminder_type', Reminder::SHIFT_START_REMINDER)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->latest()
            ->first();

        if ($existingReminder) {
            // Log::info('NotifyShiftStart: Recent reminder exists, skipping', [
            //     'captain_id' => $captain->id,
            //     'captain_name' => $captain->user->name ?? 'Unknown',
            //     'last_reminder_at' => $existingReminder->created_at
            // ]);
            $this->info("Captain {$captain->user->name} already received a shift notification recently, skipping.");
            return; // Don't send if reminder was sent recently
        }

        // Log::info('NotifyShiftStart: Proceeding with notification', [
        //     'captain_id' => $captain->id,
        //     'captain_name' => $captain->user->name ?? 'Unknown'
        // ]);

        // Create new reminder
        $this->createReminder($captain, $shiftStart);

        // Send Firebase notification
        $this->sendFirebaseNotification($captain, $shift, $shiftType, $shiftStart);
    }

    /**
     * Create reminder record
     */
    private function createReminder($captain, $shiftStart)
    {
        // Log::info('NotifyShiftStart: Creating reminder', [
        //     'captain_id' => $captain->id,
        //     'shift_start_time' => $shiftStart->format('H:i')
        // ]);

        $reminder = $captain->reminders()->create([
            'reminder_type' => Reminder::SHIFT_START_REMINDER,
            'title' => 'app/notifications.shift_start.title',
            'body' => 'app/notifications.shift_start.body',
            'metadata' => json_encode([
                'shift_start_time' => $shiftStart->format('H:i'),
            ])
        ]);

        // Log::info('NotifyShiftStart: Reminder created successfully', [
        //     'reminder_id' => $reminder->id,
        //     'captain_id' => $captain->id
        // ]);

        return $reminder;
    }

    /**
     * Send Firebase notification to captain
     */
    private function sendFirebaseNotification($captain, $shift, $shiftType, $shiftStart)
    {
        $token = $captain->accessToken->fb_token ?? null;

        // Log::info('NotifyShiftStart: Preparing Firebase notification', [
        //     'captain_id' => $captain->id,
        //     'captain_name' => $captain->user->name ?? 'Unknown',
        //     'has_firebase_token' => !empty($token),
        //     'shift_type' => $shiftType,
        //     'shift_start' => $shiftStart->format('H:i')
        // ]);

        if (!$token) {
            // Log::warning('NotifyShiftStart: No Firebase token available', [
            //     'captain_id' => $captain->id,
            //     'captain_name' => $captain->user->name ?? 'Unknown'
            // ]);
            $this->warn("No Firebase token for captain {$captain->user->name}");
            return;
        }

        $language = $captain->user->language ?? 'en';
        $shiftTime = $shiftStart->format('H:i');

        $metadata = Reminder::getNotificationMetadata(Reminder::SHIFT_START_REMINDER);

        $data = [
            'priority' => 'High',
            'reminder_type' => Reminder::SHIFT_START_REMINDER,
            'title' => __('app/notifications.shift_start.title', [], $language),
            'body' => __('app/notifications.shift_start.body', [
                'time' => $shiftTime,
                'shift' => $shiftType
            ], $language),
            'sound' => $metadata['sound'],
            'android_channel_id' => $metadata['android_channel_id'],
            'content_available' => true,
            'mutable_content' => true,
            'shift_start_time' => $shiftTime,
            'shift_type' => $shiftType,
        ];

        // Log::info('NotifyShiftStart: Sending Firebase notification', [
        //     'captain_id' => $captain->id,
        //     'language' => $language,
        //     'notification_data' => $data
        // ]);

        try {
            (new CloudMessage($captain->firebaseVersion()))
                ->send([
                    'to' => $token,
                    'notification' => $data,
                    'data' => $data
                ]);

            // Log::info('NotifyShiftStart: Firebase notification sent successfully', [
            //     'captain_id' => $captain->id,
            //     'captain_name' => $captain->user->name ?? 'Unknown',
            //     'shift_time' => $shiftTime,
            //     'shift_type' => $shiftType
            // ]);

            $this->info("Notification sent to captain {$captain->user->name} for shift at {$shiftTime}");
        } catch (\Exception $e) {
            Log::error('NotifyShiftStart: Failed to send Firebase notification', [
                'captain_id' => $captain->id,
                'captain_name' => $captain->user->name ?? 'Unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error("Failed to send notification to captain {$captain->user->name}: " . $e->getMessage());
        }
    }
}