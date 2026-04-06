<?php

namespace App\Console\Commands;

use App\Jobs\UpdateCaptainWorkLog as JobUpdateCaptainWorkLog;
use Illuminate\Console\Command;

class UpdateCaptainWorkLog extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */

    protected $signature = 'update:captain-work-log {date?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update captain work log';

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
        $date = $this->argument('date');

        if(!$date) {
            $date = now()->subDay()->format('Y-m-d');
        }

        $date = now()->parse($date);
        
        JobUpdateCaptainWorkLog::dispatch($date);
    }
}
