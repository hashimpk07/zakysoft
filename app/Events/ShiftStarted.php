<?php
namespace App\Events;

use App\Shift;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShiftStarted
{
    use Dispatchable, SerializesModels;
    public $shift;
    public function __construct(Shift $shift) { $this->shift = $shift; }
}
