<?php

namespace App\Console\Commands;

use App\Captain;
use Illuminate\Console\Command;

class CaptainVehicleRent extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'captain:rent {captain?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'record captain rent';

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
        $captains = Captain::query()
            ->whereHas('vehicle')
            ->with(['vehicleRents' => function($query) {
                $query->orderBy('id', 'desc');
            }])
            ->with('vehicle')
            ->rentalCaptain()
            ->when($this->argument('captain'), function($query, $captain) {
                $query->where('id', $captain);
            })
            ->get();

        if($captains->isEmpty()) {
            return;
        }

        foreach ($captains as $key => $captain) {
            if(!$captain->rental() && now()->isFuture(now()->parse($captain->rent_valid_from))) {
                return;
            }

            $last_rented_at = $captain->vehicleRents->first() ? now()->parse($captain->vehicleRents->first()->rented_day)->addDay() : now()->parse($captain->rent_valid_from);

            while (!$last_rented_at->isFuture(now())) {
                $captain->vehicleRents()->create([
                    'captain_id' => $captain->id,
                    'vehicle_id' => $captain->vehicle->id,
                    'amount' => $captain->daily_rent,
                    'rented_day' => $last_rented_at
                ]);

                $last_rented_at->addDay();   
            }
        }
    }
}
