<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CleanupApiUsageLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api-usage:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove API usage logs older than 2 months';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = now()->subMonths(2);
        
        $count = \App\ExternalApiUsageLog::where('created_at', '<', $date)->delete();

        $this->info("Deleted {$count} API usage logs older than {$date->toDateTimeString()}.");
    }
}
