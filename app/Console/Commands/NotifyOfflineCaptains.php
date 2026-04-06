<?php

namespace App\Console\Commands;

use App\Captain;
use App\Reminder;
use App\Services\Firebase\CloudMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class NotifyOfflineCaptains extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'captain:offline-notify';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send follow-up notifications to captains who are still offline during their shift time';

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
        $now = Carbon::now(config('app.timezone'));

        // Log::info('NotifyOfflineCaptains: Starting offline captain notification process', [
        //     'current_time' => $now->format('Y-m-d H:i:s'),
        //     'check_period_start' => $now->copy()->subMinutes(30)->format('Y-m-d H:i:s')
        // ]);

        // Find captains who have recent shift start reminders but are still offline
        $recentReminders = Reminder::where('reminder_type', Reminder::SHIFT_START_REMINDER)
            ->where('created_at', '>=', $now->subMinutes(30)) // Check last 30 minutes
            ->with(['captain.user', 'captain.accessToken', 'captain.currentShift', 'captain.shiftRule.settings'])
            ->get();

        // Log::info('NotifyOfflineCaptains: Found recent shift reminders', [
        //     'reminder_count' => $recentReminders->count(),
        //     'check_period' => '30 minutes'
        // ]);

        foreach ($recentReminders as $reminder) {
            $this->processOfflineCaptain($reminder, $now);
        }

        // Log::info('NotifyOfflineCaptains: Completed offline captain notification process');
        $this->info('Offline captain notifications processed successfully.');
    }

    /**
     * Process a captain who might still be offline
     */
    private function processOfflineCaptain($reminder, $now)
    {
        $captain = $reminder->captain;
        if (!$captain) {
            // Log::warning('NotifyOfflineCaptains: Reminder has no associated captain', [
            //     'reminder_id' => $reminder->id
            // ]);
            return;
        }

        // Log::info('NotifyOfflineCaptains: Processing offline captain', [
        //     'captain_id' => $captain->id,
        //     'captain_name' => $captain->user->name ?? 'Unknown',
        //     'reminder_id' => $reminder->id,
        //     'reminder_created_at' => $reminder->created_at
        // ]);

        //Skip if captain is already online
        if ($captain->currentShift()->exists()) {
            // Log::info('NotifyOfflineCaptains: Captain is now online, skipping', [
            //     'captain_id' => $captain->id,
            //     'captain_name' => $captain->user->name ?? 'Unknown'
            // ]);
            return;
        }

        // Skip if captain is not active
        if ($captain->status !== Captain::STATUS_ACTIVE) {
            // Log::info('NotifyOfflineCaptains: Captain is not active, skipping', [
            //     'captain_id' => $captain->id,
            //     'captain_name' => $captain->user->name ?? 'Unknown',
            //     'status' => $captain->status
            // ]);
            return;
        }

        //Check if captain should be online based on their shift rule
        if (!$this->shouldCaptainBeOnline($captain, $now)) {
            // Log::info('NotifyOfflineCaptains: Captain should not be online at this time, skipping', [
            //     'captain_id' => $captain->id,
            //     'captain_name' => $captain->user->name ?? 'Unknown'
            // ]);
            return;
        }

        // Check if we should send a follow-up notification
        if (!$this->shouldSendFollowUpNotification($reminder, $now)) {
            // Log::info('NotifyOfflineCaptains: Not time for follow-up notification yet', [
            //     'captain_id' => $captain->id,
            //     'reminder_id' => $reminder->id,
            //     'last_updated' => $reminder->updated_at
            // ]);
            return;
        }

        // Log::info('NotifyOfflineCaptains: Proceeding with follow-up notification', [
        //     'captain_id' => $captain->id,
        //     'captain_name' => $captain->user->name ?? 'Unknown'
        // ]);

        // Update reminder pause time
        $reminder->update([
            'pause_upto' => $now->addMinutes(3)
        ]);

        // Send follow-up notification
        $this->sendFollowUpNotification($captain, $reminder);
    }

    /**
     * Check if captain should be online based on their shift rule
     */
    private function shouldCaptainBeOnline($captain, $now)
    {
        if (!$captain->shiftRule) {
            // Log::info('NotifyOfflineCaptains: Captain has no shift rule', [
            //     'captain_id' => $captain->id
            // ]);
            return false;
        }

        $currentTimeCurrent = Carbon::now(config('app.timezone'));
        $currentTime = $currentTimeCurrent->format('H:i:s');
        $currentDay = $currentTimeCurrent->dayOfWeek;

        // Log::info('NotifyOfflineCaptains: Checking if captain should be online', [
        //     'captain_id' => $captain->id,
        //     'current_day' => $currentDay,
        //     'current_time' => $currentTime,
        //     'shift_rule_id' => $captain->shiftRule->id
        // ]);

        $todaySettings = $captain->shiftRule->settings()
            ->where('day', $currentDay)
            ->first();

        if (!$todaySettings) {
            // Log::info('NotifyOfflineCaptains: No shift settings found for today', [
            //     'captain_id' => $captain->id,
            //     'day' => $currentDay
            // ]);
            return false;
        }

        // Log::info('NotifyOfflineCaptains: Found shift settings for today', [
        //     'captain_id' => $captain->id,
        //     'shift_a_start' => $todaySettings->shift_a_start,
        //     'shift_a_end' => $todaySettings->shift_a_end,
        //     'shift_b_start' => $todaySettings->shift_b_start,
        //     'shift_b_end' => $todaySettings->shift_b_end
        // ]);

        // Check if current time is within any shift window
        $shifts = [
            ['start' => $todaySettings->shift_a_start, 'end' => $todaySettings->shift_a_end],
            ['start' => $todaySettings->shift_b_start, 'end' => $todaySettings->shift_b_end],
        ];

        foreach ($shifts as $index => $shift) {
            if (!$shift['start'] || !$shift['end']) {
                // Log::info('NotifyOfflineCaptains: Shift times not set, skipping', [
                //     'captain_id' => $captain->id,
                //     'shift_index' => $index,
                //     'start' => $shift['start'],
                //     'end' => $shift['end']
                // ]);
                continue;
            }

            // $shiftStart = Carbon::parse($shift['start']);
            // $shiftEnd = Carbon::parse($shift['end']);
            $shiftStart = Carbon::parse($shift['start'], 'Asia/Riyadh');
            $shiftEnd = Carbon::parse($shift['end'], 'Asia/Riyadh');

            // Allow 5 minutes grace period after shift start
            $graceStart = $shiftStart->copy()->subMinutes(5);
            $graceEnd = $shiftEnd->copy()->addMinutes(5);

            // Log::info('NotifyOfflineCaptains: Checking shift window', [
            //     'captain_id' => $captain->id,
            //     'shift_index' => $index,
            //     'shift_start' => $shiftStart->format('H:i:s'),
            //     'shift_end' => $shiftEnd->format('H:i:s'),
            //     'grace_start' => $graceStart->format('H:i:s'),
            //     'grace_end' => $graceEnd->format('H:i:s'),
            //     'current_time' => $currentTime
            // ]);

            if ($now->between($graceStart, $graceEnd,true)) {
                // Log::info('NotifyOfflineCaptains: Captain should be online (within shift window)', [
                //     'captain_id' => $captain->id,
                //     'shift_index' => $index
                // ]);
                return true;
            }
        }

        // Log::info('NotifyOfflineCaptains: Captain should not be online (outside shift windows)', [
        //     'captain_id' => $captain->id
        // ]);
        return false;
    }

    /**
     * Check if we should send a follow-up notification
     */
    private function shouldSendFollowUpNotification($reminder, $now)
    {
        // Log::info('NotifyOfflineCaptains: Checking if follow-up notification should be sent', [
        //     'reminder_id' => $reminder->id,
        //     'is_paused' => $reminder->isPaused(),
        //     'pause_upto' => $reminder->pause_upto,
        //     'last_updated' => $reminder->updated_at
        // ]);

        // Don't send if reminder is still paused
        if ($reminder->isPaused()) {
            // Log::info('NotifyOfflineCaptains: Reminder is still paused', [
            //     'reminder_id' => $reminder->id,
            //     'pause_upto' => $reminder->pause_upto
            // ]);
            return false;
        }

        // Send follow-up every 3 minutes
        $lastNotification = $reminder->updated_at;
        $timeSinceLastNotification = $now->diffInMinutes($lastNotification);

        // Log::info('NotifyOfflineCaptains: Time check for follow-up', [
        //     'reminder_id' => $reminder->id,
        //     'minutes_since_last' => $timeSinceLastNotification,
        //     'required_minutes' => 3
        // ]);

        $shouldSend = $timeSinceLastNotification >= 3;

        // Log::info('NotifyOfflineCaptains: Follow-up notification decision', [
        //     'reminder_id' => $reminder->id,
        //     'should_send' => $shouldSend
        // ]);

        return $shouldSend;
    }

    /**
     * Send follow-up notification to captain
     */
    private function sendFollowUpNotification($captain, $reminder)
    {
        $token = $captain->accessToken->fb_token ?? null;

        // Log::info('NotifyOfflineCaptains: Preparing follow-up Firebase notification', [
        //     'captain_id' => $captain->id,
        //     'captain_name' => $captain->user->name ?? 'Unknown',
        //     'reminder_id' => $reminder->id,
        //     'has_firebase_token' => !empty($token)
        // ]);

        if (!$token) {
            // Log::warning('NotifyOfflineCaptains: No Firebase token available for follow-up', [
            //     'captain_id' => $captain->id,
            //     'captain_name' => $captain->user->name ?? 'Unknown'
            // ]);
            $this->warn("No Firebase token for captain {$captain->user->name}");
            return;
        }

        $language = $captain->user->language ?? 'en';
        $metadata_reminder = $reminder->metadata ?? [];
        $shiftTime = $metadata_reminder['shift_start_time'] ?? 'your scheduled time';

        $metadata = Reminder::getNotificationMetadata(Reminder::SHIFT_START_REMINDER);

        // Log::info('NotifyOfflineCaptains: Follow-up notification metadata', [
        //     'captain_id' => $captain->id,
        //     'language' => $language,
        //     'shift_time' => $shiftTime,
        //     'metadata' => $metadata
        // ]);

        $data = [
            'priority' => 'High',
            'reminder_type' => Reminder::SHIFT_START_REMINDER,
            'title' => __('app/notifications.shift_start_followup.title', [], $language),
            'body' => __('app/notifications.shift_start_followup.body', [
                'time' => $shiftTime
            ], $language),
            'sound' => $metadata['sound'],
            'android_channel_id' => $metadata['android_channel_id'],
            'content_available' => true,
            'mutable_content' => true,
            'shift_start_time' => $shiftTime,
            'is_followup' => true,
        ];

        // Log::info('NotifyOfflineCaptains: Sending follow-up Firebase notification', [
        //     'captain_id' => $captain->id,
        //     'notification_data' => $data
        // ]);

        try {
            (new CloudMessage($captain->firebaseVersion()))
                ->send([
                    'to' => $token,
                    'notification' => $data,
                    'data' => $data
                ]);

            // Log::info('NotifyOfflineCaptains: Follow-up Firebase notification sent successfully', [
            //     'captain_id' => $captain->id,
            //     'captain_name' => $captain->user->name ?? 'Unknown',
            //     'reminder_id' => $reminder->id,
            //     'shift_time' => $shiftTime
            // ]);

            $this->info("Follow-up notification sent to captain {$captain->user->name}");
        } catch (\Exception $e) {
            Log::error('NotifyOfflineCaptains: Failed to send follow-up Firebase notification', [
                'captain_id' => $captain->id,
                'captain_name' => $captain->user->name ?? 'Unknown',
                'reminder_id' => $reminder->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error("Failed to send follow-up notification to captain {$captain->user->name}: " . $e->getMessage());
        }
    }
}