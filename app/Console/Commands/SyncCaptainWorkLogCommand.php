<?php

namespace App\Console\Commands;

use App\Jobs\UpdateCaptainWorkLog as JobUpdateCaptainWorkLog;
use Illuminate\Console\Command;
use Carbon\Carbon;

class SyncCaptainWorkLogCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'captain:sync-work-log {--period= : The period to sync (daily, weekly or monthly)} {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync captain work log for a specific period (daily, weekly or monthly)';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $period = $this->option('period');

        if (!in_array($period, ['daily', 'weekly', 'monthly'])) {
            $this->error('Invalid period. Please specify "daily", "weekly" or "monthly".');
            return 1;
        }

        if ($period === 'daily') {
            $daysToSync = 1;
        } elseif ($period === 'weekly') {
            $daysToSync = 7;
        } else {
            $daysToSync = 30;
        }
        
        $endDate = now();
        // If daily, we want to sync "yesterday's" business day.
        // The JobUpdateCaptainWorkLog uses the date provided to calculate the business day (06:00 to 05:59).
        // So if it runs at 08:00 today, subDay(1) gives yesterday's date, which the job will use to sync yesterday 06:00 to today 05:59.
        $startDate = now()->subDays($daysToSync);

        $this->info("Syncing captain work log from {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}...");

        // Iterate through each day in the period and dispatch the job
        $currentDate = $endDate->copy()->subDay(); // We always sync completed business days
        
        while ($currentDate->greaterThanOrEqualTo($startDate)) {
            $this->info("Dispatching sync for date: " . $currentDate->format('Y-m-d'));
            
            // Dispatch the job for each specific date
            JobUpdateCaptainWorkLog::dispatch($currentDate->copy());
            
            $currentDate->subDay();
        }

        $this->info("Captain work log sync jobs dispatched successfully for {$period} period.");
        
        return 0;
    }
}
