<?php

namespace App\Console\Commands;

use App\Captain;
use App\CaptainLocationLog as AppCaptainLocationLog;
use App\Services\Position;
use App\Services\Traccar;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CaptainLocationLog extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:captain';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find captain location and zone';

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
     * @return int
     */
    public function handle()
    {
        $captains = Captain::with('user')->whereHas('shifts', function(Builder $query){
                        $query->where('shift_end', null);
                    })->get();
        
        $captains->map(function($captain){
            if(isset($captain->user->locationIdentifier) && $captain->user->locationIdentifier) {
                $position = Traccar::position($captain->user->locationIdentifier);
                if($position instanceof Position) {
                    $id = 0;
           
                    $sqlQuery = DB::raw("SELECT name,id From zones WHERE MBRContains(ST_GeomFromText(zones.polygon),ST_GeomFromText('Point(" .  $position->getLat() . " " . $position->getLng() . ")'))");
                    $results = DB::select($sqlQuery->getValue(DB::connection()->getQueryGrammar()));
                    if (!empty($results)) {
                        $id = $results[0]->id;
                    }

                    $captain->locations()->save(new AppCaptainLocationLog([
                        "latitude" => $position->getLat(),
                        "longitude" =>  $position->getLng(),
                        "speed" => $position->getMeta('speed'),
                        'zone_id' => $id,
                        "battery_level" => $position->getMeta('batteryLevel'),
                        "last_updated_at" => $position->getMeta('lastUpdatedAt')
                    ]));
                }
            }
        });
    }
}
