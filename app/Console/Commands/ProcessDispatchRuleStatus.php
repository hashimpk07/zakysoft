<?php

namespace App\Console\Commands;

use App\DispatchRule;
use Illuminate\Console\Command;

class ProcessDispatchRuleStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dispatch-rules:process-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enable or disable dispatch rules when scheduled time is met';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = now();

        /**
         * ENABLE rules when scheduled "from" time is reached
         */
        DispatchRule::where('status', DispatchRule::STATUS_DISABLED)
            ->whereNotNull('status_applied_from')
            ->where('status_applied_from', '<=', $now)
            ->update([
                'status' => 1,
                'status_applied_from' => $now,
                'status_applied_to' => null,
            ]);

        /**
         * DISABLE rules when scheduled "to" time is reached
         */
        DispatchRule::where('status', DispatchRule::STATUS_ENABLED)
            ->whereNotNull('status_applied_to')
            ->where('status_applied_to', '<=', $now)
            ->update([
                'status' => 2,
                'status_applied_from' => $now,
                'status_applied_to' => null,
            ]);

        $this->info('Dispatch rule statuses processed successfully.');

        return Command::SUCCESS;
    }
}
