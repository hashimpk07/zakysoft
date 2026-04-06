<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use  App\Actions\CaptainActiveLowPerformanceAction;
use Illuminate\Support\Facades\Log;

class CaptainActiveLowPerformance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Example: php artisan captain:active-low-performance
     */
    protected $signature = 'captain:active-low-performance';
    /**
     * The console command description.
     */
    protected $description = 'Generate captain performance report every 15 minutes';
    /**
     * Execute the console command.
     */
    public function handle()
    {
        (new CaptainActiveLowPerformanceAction)->execute();
        // Log::info("Consolidated commission report generated successfully");
    }
}
