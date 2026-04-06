<?php
namespace App\Listeners;

use App\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CreateAuditLogFromEvent implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle($event)
    {
        if (property_exists($event, 'shift')) {
            $shift = $event->shift;
            AuditLog::create([
                'user_id' => $shift->user_id,
                'action' => class_basename($event),
                'old_status' => null,
                'new_status' => null,
                'meta' => ['shift_id' => $shift->id],
                'performed_by' => $shift->user_id
            ]);
            Log::info("CreateAuditLogFromEvent handled for shift={$shift->id}");
        } elseif (property_exists($event, 'break')) {
            $b = $event->break;
            AuditLog::create([
                'user_id' => $b->shift->user_id ?? null,
                'action' => class_basename($event),
                'old_status' => null,
                'new_status' => null,
                'meta' => ['break_id' => $b->id, 'shift_id' => $b->shift_id],
                'performed_by' => $b->shift->user_id ?? null
            ]);
            Log::info("CreateAuditLogFromEvent handled for break={$b->id}");
        }
    }
}
