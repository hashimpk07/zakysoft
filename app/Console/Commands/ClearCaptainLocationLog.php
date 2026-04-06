<?php

namespace App\Console\Commands;

use App\Captain;
use App\Services\Firebase\CloudMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearCaptainLocationLog extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */

    protected $signature = 'captain:clear-location';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear All Location Logs';

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
        $lastUpdateLogs = DB::table('captain_location_logs')->select(DB::raw('MAX(id) as id'))->groupBy('captain_id')->get();
        DB::table('captain_location_logs')->whereNotIn('id', $lastUpdateLogs->pluck('id'))->delete();
    }
}
