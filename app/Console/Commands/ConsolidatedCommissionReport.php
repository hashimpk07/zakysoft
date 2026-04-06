<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use  App\Actions\ConsolidatedCommissionReportAction;
use Illuminate\Support\Facades\Log;

class ConsolidatedCommissionReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'captain:consolidated-commission-report-daily {date?}';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the daily consolidated commission report';
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dateArg = $this->argument('date') ?? now()->subDay()->toDateString();
        (new ConsolidatedCommissionReportAction)->execute($dateArg);
        // Log::info("Consolidated commission report generated successfully {$dateArg}");
    }
}
