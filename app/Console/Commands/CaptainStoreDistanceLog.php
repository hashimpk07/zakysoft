<?php

namespace App\Console\Commands;

use App\Captain;
use App\CaptainLocationLog;
use App\CaptainStore;
use App\ClientShop;
use App\Order;
use App\Services\Map;
use App\Services\Position;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CaptainStoreDistanceLog extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:captain-store';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find distance between captain and store';

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
        $new_orders = Order::readyToDispatch()
                        ->autoAssignable()
                        ->distinct()
                        ->get(['shopname', 'region_id']);
                        
        foreach ($new_orders as $key => $new_order) {
            $client_shop = ClientShop::with('region')->find($new_order->shopname);

            if(!$client_shop->region) {
                // Log::info("Can't find shop region, shop: ". $client_shop->id);
                continue;
            }

            //DB::enableQueryLog();
            $captains = Captain::with('location')
                ->whereHas('regions', function($query) use ($client_shop) {
                    $query->where('regions.id', $client_shop->region->id);
                })
                ->online()
                ->get();

            //Log::channel('auto_assigning')->debug('Query executed captain store distance log', [DB::getQueryLog()]);
            if($captains) {
                foreach ($captains as $key => $captain) {
                    $this->logLocation($client_shop, $captain);
                }
            }
        }
    }

    public function logLocation($shop, $captain)
    {
        if($captain->location instanceof CaptainLocationLog) {
            $storePosition = new Position(...array_reverse(explode(',', $shop->location)));
            $captain_location = new Position($captain->location->longitude, $captain->location->latitude);
            $direction = (new Map())->AirDirection($captain_location, $storePosition);
    
            // CaptainStore::updateOrCreate([
            //     'captain_id' => $captain->id,
            //     'client_shop_id' => $shop->id
            // ], [
            //     'distance' => $direction->distance(),
            //     'traveling_time' => $direction->duration()
            // ]);

            $batch_data[] = [
                'captain_id' => $captain->id,
                'client_shop_id' => $shop->id,
                'distance' => $direction->distance(),
                'traveling_time' => $direction->duration(),
                'updated_at' => now(),
                'created_at' => now()
            ];

            if (!empty($batch_data)) {
                CaptainStore::upsert($batch_data, ['captain_id', 'client_shop_id'], ['distance', 'traveling_time', 'updated_at']);
            }
        }
    }
}
