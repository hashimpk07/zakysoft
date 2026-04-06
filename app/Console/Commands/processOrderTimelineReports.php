<?php

namespace App\Console\Commands;

use App\Jobs\ProcessOrderTimelineReportJob;
use Illuminate\Console\Command;

class processOrderTimelineReports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ordersTimelineReport:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process orders Timeline in chunks and update reports';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Dispatching the ProcessOrdersTimlineJob...");

        // Dispatch the job
        ProcessOrderTimelineReportJob::dispatch();

        $this->info("ProcessOrdersTimelineJob has been dispatched successfully.");
        return 0;
    }
}
