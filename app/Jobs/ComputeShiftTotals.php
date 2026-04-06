<?php
namespace App\Jobs;

use App\Shift;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class ComputeShiftTotals implements ShouldQueue
{
    use Dispatchable, Queueable;

    protected $shiftId;

    public function __construct($shiftId)
    {
        $this->shiftId = $shiftId;
    }

    public function handle()
    {
        $shift = Shift::with('breaks')->find($this->shiftId);
        if (! $shift) {
            Log::error("ComputeShiftTotals: Shift not found id={$this->shiftId}");
            return;
        }

        if (! $shift->end_time) {
            Log::info("ComputeShiftTotals: Shift end_time is null for id={$shift->id}");
            return;
        }

        $totalSeconds = $shift->end_time->diffInSeconds($shift->start_time);
        $breakSeconds = $shift->breaks->sum(function($b) { return $b->duration_seconds ?? 0; });
        $workSeconds = max(0, $totalSeconds - $breakSeconds);

        $shift->total_working_seconds = $workSeconds;
        $shift->save();

        Log::info("ComputeShiftTotals: shift={$shift->id}, totalSeconds={$totalSeconds}, breakSeconds={$breakSeconds}, workSeconds={$workSeconds}");
    }
}
