<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\DispatchRule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class AutoEnableDispatchRules extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:auto-enable-dispatch-rules';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto Enable disabled dispatch rules if end time has passed';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = Carbon::now();

        // DB::enableQueryLog(); // Enable query logging for debugging
        $expiredRules = DispatchRule::where('status', 2)
            ->where('status_applied_to', '<', $now)
            ->get();
        // Log the query for debugging
        // Log::debug('AutoEnableDispatchRules Query', [
        //     'query' => DB::getQueryLog(),
        //     'expired_count' => $expiredRules->count(),
        //     'now' => $now->toDateTimeString()
        // ]);
        foreach ($expiredRules as $rule) {
            // Log::info("Re-enabling dispatch rule ID {$rule->id}", [
            //     'name' => $rule->name,
            //     'previous_status_from' => $rule->status_applied_from,
            //     'previous_status_to' => $rule->status_applied_to,
            //     'now' => $now->toDateTimeString()
            // ]);

            $rule->status = 1; // Enable the rule
            $rule->status_applied_from = now()->format('Y-m-d H:i:s');
            $rule->status_applied_to = null;
            $rule->save();
        }

        $this->info("Checked and enabled " . $expiredRules->count() . " dispatch rule(s).");
    }
}
