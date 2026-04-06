<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Captain;
use App\Jobs\CalculateCaptainCommissionJob;
use Illuminate\Support\Facades\Log;

class DispatchDailyCommissionCalculation extends Command
{
    protected $signature = 'commission:calculate-daily {date?}';
    protected $description = 'Dispatch daily commission calculation for all captains';

    public function handle()
    {
        $dateArg = $this->argument('date') ?? now()->subDay()->toDateString();
        $date = \Carbon\Carbon::parse($dateArg);
        //$captains = Captain::with('commissionRule')->get();
        $captains = Captain::whereHas('commissionRule', function ($query) {
            $query->where('commission_type', 2);
        })->with('commissionRule')->get();

        foreach ($captains as $captain) {
            CalculateCaptainCommissionJob::dispatch($captain, $date);
        }

        // Log::info("Dispatched commission jobs for date {$date}");
        $this->info('Dispatched commission jobs successfully.');
    }
}
