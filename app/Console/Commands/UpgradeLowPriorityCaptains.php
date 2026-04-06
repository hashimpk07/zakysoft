<?php


namespace App\Console\Commands;

use App\Captain;
use App\AutoAssignPriority;
use Illuminate\Console\Command;
use Carbon\Carbon;

class UpgradeLowPriorityCaptains extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'captain:upgrade-from-low';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Upgrade captains from low to medium priority after 2 hours';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $lowPriorityId = AutoAssignPriority::where('name', 'like', '%low%')->first()?->id;
        $mediumPriorityId = AutoAssignPriority::where('name', 'like', '%medium%')->first()?->id;
        
        if (!$lowPriorityId || !$mediumPriorityId) {
            $this->error('Unable to find required priority levels');
            return 1;
        }
        
        $twoHoursAgo = Carbon::now()->subHours(2);
        
        // Find all captains that have been in low priority for at least 2 hours
        $captains = Captain::where('auto_assign_priority_id', $lowPriorityId)
            ->whereNotNull('low_priority_since')
            ->where('low_priority_since', '<=', $twoHoursAgo)
            ->get();
            
        $upgradeCount = 0;
        
        foreach ($captains as $captain) {
            $captain->auto_assign_priority_id = $mediumPriorityId;
            $captain->low_priority_since = null;
            $captain->save();
            
            $upgradeCount++;
            \Log::info("Captain {$captain->id} automatically upgraded to Medium priority after 2+ hours in Low priority");
        }
        
        $this->info("Upgraded {$upgradeCount} captains from Low to Medium priority");
        
        return 0;
    }
}