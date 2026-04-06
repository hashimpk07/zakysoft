<?php

namespace App\Repositories\Mobile;

use App\Interfaces\Mobile\ReminderInterface;
use App\Reminder;

class ReminderInterfaceRepository implements ReminderInterface
{
    public function pauseReminder(int $captainId)
    {
        $reminder = Reminder::where('captain_id', $captainId)
            ->where('reminder_type', Reminder::SHIFT_CLOSE)
            ->latest()
            ->first();

        if ($reminder) {
            $reminder->pause_upto = now()->addHours(1);
            $reminder->save();
            return true;
        }

        return false;
    }
}
