<?php

namespace App\Console\Commands;

use App\Events\ActivityCheckRequested;
use App\ActivityCheck;
use App\PresenceStatus;
use App\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendRandomActivityCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'operation:check-activity';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send random activity checks to online employees';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Get user IDs with pending activity checks
        $usersWithPendingChecks = ActivityCheck::where('status', 'pending')->pluck('user_id')->toArray();

        // Get all on-duty users excluding those with pending checks
        $onDuty = PresenceStatus::onDuty()->whereNotIn('user_id', $usersWithPendingChecks)->pluck('user_id');

        $count = $onDuty->count();
        Log::channel('shift_tracking')->info("Found {$count} online users for activity check.");

        if ($count === 0) {
            $this->info('No online users found for activity check.');
            return;
        }

        // Choose half of them randomly (at least 1)
        $selectedCount = max(1, floor($count / 2));
        $selectedUserIds = $count > $selectedCount ? $onDuty->random($selectedCount) : $onDuty;

        Log::channel('shift_tracking')->info("Selected {$selectedCount} users for activity check: ", $selectedUserIds->toArray());

        // Get all users in one go
        $users = User::whereIn('id', $selectedUserIds)->get();

        // Create activity checks + broadcast
        foreach ($users as $user) {
            $a = rand(1, 10);
            $b = rand(1, 10);

            $check = ActivityCheck::create([
                'user_id' => $user->id,
                'a' => $a,
                'b' => $b,
                'correct_answer' => $a + $b,
                'expires_at' => now()->addMinutes(3),
            ]);

            Log::channel('shift_tracking')->info("Created activity check id={$check->id} for user_id={$user->id}");
            Log::channel('shift_tracking')->info("Broadcasting activity check id={$check->id} to user_id={$user->id}");

            broadcast(new ActivityCheckRequested($check, $user));
        }

        $this->info("Activity checks sent successfully to {$selectedCount} users.");
    }
}
