<?php

namespace App\Console\Commands;

use App\Jobs\ProcessCaptainTimelineReportJob;
use Illuminate\Console\Command;

class ProcessCaptainTimelineReports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'captainOrdersTimelineReport:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process captain Orders Timeline in chunks and update reports';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Dispatching the ProcessCaptainTimelineReportsJob...");

        // Dispatch the job
        ProcessCaptainTimelineReportJob::dispatch();

        $this->info("ProcessCaptainTimelineReports has been dispatched successfully.");
        return 0;
    }
}
