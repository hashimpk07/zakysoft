<?php

namespace App\Jobs;

use App\Actions\UpdateCaptainWorkLog as ActionsUpdateCaptainWorkLog;
use App\Captain;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateCaptainWorkLog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1000;

    public function __construct(
      private Carbon $date
    )
    {}

    public function handle(): void
    {
      foreach (Captain::all() as $key => $captain) {
        (new ActionsUpdateCaptainWorkLog())->execute($captain, $this->date);
      }
    }
}
