<?php

namespace App\Actions;

use App\AutoAssignPriority;
use App\Captain;
use App\Order;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\CaptainStatusLog;

class AdjustCaptainPriority
{
    /**
     * Dynamically adjust captain's priority based on completed order volume
     * versus their target volume.
     *
     * @param Captain $captain
     * @return void
     */
    public function execute(Captain $captain)
    {
        // Skip adjustment if captain isn't in auto assignment mode
        if ($captain->assignment_type != 1) {
            return;
        }

        // Skip if no target volume is set
        if (!$captain->target_volume) {
            return;
        }

        // Count today's completed orders for this captain
        // Calculate business day range (6:00 AM current day to 5:59:59 AM next day)
        $businessDayStart = Carbon::today()->setTime(6, 0, 0);
        $businessDayEnd = Carbon::tomorrow()->setTime(5, 59, 59);
        
        // If current time is before 6 AM, we're still in previous business day
        if (Carbon::now()->hour < 6) {
            $businessDayStart = Carbon::yesterday()->setTime(6, 0, 0);
            $businessDayEnd = Carbon::today()->setTime(5, 59, 59);
        }

        $completedOrdersCount = Order::where('captain_id', $captain->id)
            ->where("status_id", OrderStatus::DELIVERED)
            ->where('delivery_date', '>=', $businessDayStart)
            ->where('delivery_date', '<=', $businessDayEnd)
            ->whereNotNull('delivery_date')
            ->count();

        $completionPercentage = ($completedOrdersCount / $captain->target_volume) * 100;

        // Add debugging
        \Log::info("Captain {$captain->id} Debug: {$completedOrdersCount} orders / {$captain->target_volume} target = {$completionPercentage}%");

        // Get priority IDs
        $highPriorityId = $this->getPriorityId('high');
        $mediumPriorityId = $this->getPriorityId('medium');
        $lowPriorityId = $this->getPriorityId('low');

        // Add debugging for priority IDs
        \Log::info("Priority IDs - High: {$highPriorityId}, Medium: {$mediumPriorityId}, Low: {$lowPriorityId}");

        // Check if captain is in low priority and needs to be upgraded to medium after 2 hours
        if ($captain->auto_assign_priority_id == $lowPriorityId && $captain->low_priority_since) {
            $lowPrioritySince = new Carbon($captain->low_priority_since);
            $hoursInLowPriority = $lowPrioritySince->diffInHours(Carbon::now());
            
            // If captain has been in low priority for 2 hours or more, upgrade to medium
            if ($hoursInLowPriority >= 2) {
                $captain->auto_assign_priority_id = $mediumPriorityId;
                $captain->low_priority_since = null;
                $captain->save();

                $this->saveCaptainStatusLog($captain->id, "Low to Medium after {$hoursInLowPriority} hours");

                \Log::info("Captain {$captain->id} upgraded to Medium priority after {$hoursInLowPriority} hours in Low priority");
                return;
            }
            
            \Log::info("Captain {$captain->id} remains in Low priority for {$hoursInLowPriority} hours (needs 2 hours to upgrade)");
            return;
        }

        // Determine appropriate priority based on thresholds
        if ($completionPercentage <= 70) {
            // 0% - 70%: High Priority
            $newPriorityId = $highPriorityId;
            $priorityLevel = 'High';
        } elseif ($completionPercentage <= 100) {
            // 71% - 100%: Medium Priority
            $newPriorityId = $mediumPriorityId;
            $priorityLevel = 'Medium';
        } else {
            // Above 100%: Low Priority
            $newPriorityId = $lowPriorityId;
            $priorityLevel = 'Low';
        }
       
        // Add debugging for determined priority
        \Log::info("Captain {$captain->id} should have {$priorityLevel} priority (ID: {$newPriorityId}). Current priority ID: {$captain->auto_assign_priority_id}");

        // Update captain's priority if it's changed
        if ($captain->auto_assign_priority_id != $newPriorityId) {
            $captain->auto_assign_priority_id = $newPriorityId;

            $reason = "{$this->getPriorityName($captain->auto_assign_priority_id)} to {$priorityLevel}";
            $this->saveCaptainStatusLog($captain->id, $reason);
            
            // If moving to low priority, set the timestamp
            if ($newPriorityId == $lowPriorityId) {
                $captain->low_priority_since = Carbon::now();
                $reason = "{$this->getPriorityName($captain->auto_assign_priority_id)} to Low priority, timer started";
                $this->saveCaptainStatusLog($captain->id, $reason);
                \Log::info("Captain {$captain->id} moved to Low priority, timer started");
            } else {
                $captain->low_priority_since = null;
            }
            
            $captain->save();

            // Log the priority change for auditing
            \Log::info("Captain {$captain->id} priority adjusted to {$this->getPriorityName($newPriorityId)} based on {$completedOrdersCount}/{$captain->target_volume} orders ({$completionPercentage}%)");
        } else {
            \Log::info("Captain {$captain->id} priority unchanged - already at correct level");
        }
    }
    private function saveCaptainStatusLog(int $captain, string $reason)
    {
        CaptainStatusLog::create([
            'user_id'    => config('app.system_user'),
            'captain_id' => $captain,
            'reason'     => $reason,
        ]);
    }
    /**
     * Get priority ID by name
     *
     * @param string $priorityName
     * @return int|null
     */
    private function getPriorityId(string $priorityName)
    {
        $priority = AutoAssignPriority::where('name', 'like', "%{$priorityName}%")->first();
        return $priority ? $priority->id : null;
    }

    /**
     * Get priority name by ID
     *
     * @param int $priorityId
     * @return string
     */
    private function getPriorityName(int $priorityId)
    {
        $priority = AutoAssignPriority::find($priorityId);
        return $priority ? $priority->name : 'unknown';
    }
}