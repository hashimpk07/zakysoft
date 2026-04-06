<?php

namespace App\Console\Commands;

use App\Captain;
use App\AutoAssignPriority;
use Illuminate\Console\Command;
use Carbon\Carbon;

class ResetCaptainPriorities extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'captain:reset-priorities';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset auto-assignment captains to high priority at the end of each business day';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Get high priority ID (assuming it's 1 based on your requirement)
        $highPriorityId = AutoAssignPriority::where('name', 'like', '%high%')->first()?->id ?? 1;

        // Find all captains with auto assignment type
        $captains = Captain::where('assignment_type', Captain::ASSIGNMENT_TYPE_AUTO)->get();

        $resetCount = 0;

        foreach ($captains as $captain) {
            // Only reset if priority is not already high
            if ($captain->auto_assign_priority_id != $highPriorityId) {
                $captain->auto_assign_priority_id = $highPriorityId;
                $captain->save();
                $resetCount++;

                \Log::info("Captain {$captain->id} priority reset to high at end of business day");
            }
        }

        $this->info("Reset {$resetCount} captains to high priority for new business day");
        \Log::info("Business day reset: {$resetCount} captains reset to high priority");

        return 0;
    }
}