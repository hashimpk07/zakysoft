<?php
namespace App\Events;

use App\BreakModel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BreakStarted
{
    use Dispatchable, SerializesModels;
    public $break;
    public function __construct(BreakModel $break) { $this->break = $break; }
}
