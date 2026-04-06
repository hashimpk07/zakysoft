<?php

namespace App\Console\Commands;

use App\Jobs\ProcessOrdersReportJob;
use Illuminate\Console\Command;

class ProcessOrdersReportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ordersReport:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process orders in chunks and update reports';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Dispatching the ProcessOrdersJob...");

        // Dispatch the job
        ProcessOrdersReportJob::dispatch();

        $this->info("ProcessOrdersJob has been dispatched successfully.");
        return 0;
    }
}
