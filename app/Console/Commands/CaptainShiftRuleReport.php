<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use  App\Actions\CaptainShiftRuleReportAction;

class CaptainShiftRuleReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'captain:shift-rule-report {date?}';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Captain Shift rule report description';
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dateArg = $this->argument('date') ?? now()->subDay()->toDateString();
        (new CaptainShiftRuleReportAction)->execute($dateArg);
        // Log::info("Captain Shift rule report generated successfully {$dateArg}");

    }
}
