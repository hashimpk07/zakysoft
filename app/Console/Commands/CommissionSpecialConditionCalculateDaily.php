<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\SpecialConditionCommissionJob;
use Illuminate\Support\Facades\Log;

class CommissionSpecialConditionCalculateDaily extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'commission:special-condition-calculate-daily {date?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate commission special conditions daily';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dateArg = $this->argument('date') ?? now()->subDay()->toDateString();
        (new SpecialConditionCommissionJob($dateArg))->handle();
        // Log::info("Dispatched special commission jobs for date {$dateArg}");
        
    }
}