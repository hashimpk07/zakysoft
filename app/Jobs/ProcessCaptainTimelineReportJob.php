<?php

namespace App\Jobs;

use App\Actions\UpdateCaptainWorkLog;
use App\Captain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessCaptainTimelineReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */

    public $timeout = 86400; //24 hours

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        DB::transaction(function () {
            Captain::query()
                ->chunk(1000, function ($captains) {
                    foreach ($captains as $captain) {
                        (new UpdateCaptainWorkLog())->execute($captain);
                    }
                });
        });

    }

}
