<?php

namespace App\Console\Commands;

use App\ActivityCheck;
use App\Notifications\BreakStatusNotification;
use App\Services\ShiftService;
use App\User;
use Illuminate\Console\Command;

class ForceBreakProvider extends Command
{

    public function __construct(private readonly ShiftService $shiftService)
    {
        parent::__construct();
    }
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'force-break-operation';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command forces a break for operations who have not responded to activity checks';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $activityCheck = ActivityCheck::where('status', 'pending')->where('expires_at', '<', now())->get();

        foreach ($activityCheck as $check) {
            $this->shiftService->logShiftAction("Forcing break for user_id={$check->user_id} due to unanswered activity check_id={$check->id}");

            $this->shiftService->activityCheck(0, $check, $check->user_id);

            $this->shiftService->logShiftAction("User_id={$check->user_id} status changed to break due to unanswered activity check_id={$check->id}");

            $user = User::with('presenceStatus')->find($check->user_id);

            if ($user && $user->presenceStatus) {
                $user->notify(new BreakStatusNotification($user->PresenceStatus));

                $this->shiftService->logShiftAction("BreakStatusNotification sent to user_id={$check->user_id} due to unanswered activity check_id={$check->id}");
            }
        }

        $this->info('Force break provider command executed successfully.');
    }
}
