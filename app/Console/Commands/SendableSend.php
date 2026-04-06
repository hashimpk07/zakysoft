<?php

namespace App\Console\Commands;

use App\Sendable;
use Illuminate\Console\Command;

class SendableSend extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */

    protected $signature = 'sendable:send {frequency?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sending sendable if it available';

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
        $frequency = $this->argument('frequency');

        if ($frequency) {
            $sendables = Sendable::frequency($frequency)->get();
        } else {
            $time = now()->format('H:i') . ':00';
            $sendables = Sendable::where('time', $time)->get();
        }

        foreach ($sendables as $key => $sendable) {
            if (!$frequency) {
                if ($sendable->frequency === 'weekly' && !now()->isMonday()) continue;
                if ($sendable->frequency === 'monthly' && now()->day !== 1) continue;
                if ($sendable->frequency === 'yearly' && now()->dayOfYear !== 1) continue;
            }

            unserialize($sendable->class)->despatch($sendable);
        }
    }
}
