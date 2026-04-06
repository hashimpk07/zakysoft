<?php

namespace App\Console\Commands;

use App\Notifications\BreakStatusNotification;
use App\Services\ShiftService;
use App\Shift;
use App\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ForceCloseOperationShift extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'force-close-operation-shift';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command will forcefully complete the shift for any user who remains active after one business day.';

    /**
     * Execute the console command.
     */
    public function handle(ShiftService $shiftService)
    {
        $end = now()->setTime(6, 0, 0);
        $start = (clone $end)->subDay();

        $expiredShifts = Shift::select('id', 'user_id')
            ->whereBetween('start_time', [$start, $end])
            ->whereNull('end_time')
            ->where('status', 'active')
            ->get();

        $shiftService->logShiftAction('Expired shift processing started', [
            'count' => $expiredShifts->count(),
            'window' => [$start->toDateTimeString(), $end->toDateTimeString()],
        ]);

        foreach ($expiredShifts as $shift) {
            $presence = $shiftService->getPresence($shift->user_id);

            if ($presence) {
                if ($presence->status === 'on_break') {
                    $shiftService->endBreak($presence->active_break_id, $shift->user_id);
                }

                if (in_array($presence->status, ['on_duty', 'on_break'])) {
                    $shiftService->endShift($shift->id, $shift->user_id);
                }
            }

            $user = User::with('presenceStatus')->find($shift->user_id);

            if ($user && $user->presenceStatus) {
                $user->notify(new BreakStatusNotification($user->presenceStatus));
                $shiftService->logShiftAction("BreakStatusNotification sent to user_id={$shift->user_id} (online after 5:59 AM)");
            }
        }
    }
}
