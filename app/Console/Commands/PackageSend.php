<?php

namespace App\Console\Commands;

use App\Captain;
use App\Jobs\BroadcastPackage;
use App\Order;
use App\Package;
use App\PackageOrder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PackageSend extends Command
{
    protected $signature = 'package:send';
    protected $fallback = 5 * 60;

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
        Log::channel('auto_assigning')->debug('Starting package:send command');
        //DB::enableQueryLog();
        $readyToSendPackages = Package::with('shop.region')
            ->whereDoesntHave('deliveryRequests', function ($query) {
                $query->whereRaw('DATE_FORMAT(sended_at, "%Y-%m-%d %H:%i") = DATE_FORMAT(NOW(), "%Y-%m-%d %H:%i")');
            })
            ->whereDoesntHave('deliveryRequests', function ($query) {
                $query->whereRaw('
                    now() 
                    <= 
                    TIMESTAMPADD(
                        SECOND,
                        (select `dispatch_notification_preferences`.`waiting_time` from `dispatch_rules` 
                            left join `dispatch_notification_preferences` on `dispatch_rules`.`id`=`dispatch_notification_preferences`.`dispatch_rule_id` 
                            left join `package_delivery_requests` on `packages`.`id`=`package_delivery_requests`.`package_id` 
                            where  
                                now() >= TIMESTAMPADD(SECOND, dispatch_notification_preferences.waiting_time, IFNULL(package_delivery_requests.sended_at, IFNULL(packages.dispatch_after, packages.created_at))) AND
                                `dispatch_rules`.`id`=`packages`.`dispatch_rule_id`
                            order by `dispatch_notification_preferences`.`waiting_time` desc limit 1
                        ),
                        IFNULL(package_delivery_requests.sended_at, packages.created_at) 
                    )'
                );
            })
            ->whereHas('orders.order', function (Builder $query) {
                $query->readyToDispatch();
                $query->autoAssignable();
            })
            ->where('packages.captain_id', null)
            ->where('packages.dispatch_after', '<=', now())
            ->orderBy('packages.created_at', 'asc')
            ->get();

        Log::channel('auto_assigning')->info('Packages fetched for auto-assign check', [
            'total_packages' => $readyToSendPackages->count(),
            'package_ids' => $readyToSendPackages->pluck('id'),
            //'packages_query' => $readyToSendPackages
        ]);

        foreach ($readyToSendPackages as $key => $package) {
            if (!Cache::get('package_sending_lock_' . $package->id)) {
                Cache::put('package_sending_lock_' . $package->id, true, 55);

                Log::channel('auto_assigning')->debug('Dispatching BroadcastPackage job '. now()->format('Y-m-d H:i:s'), [
                    'package_id' => $package->id,
                ]);
                // if($package->id == 1975646)
                //     BroadcastPackage::dispatch($package);
                BroadcastPackage::dispatch($package);
                continue;
            } else {
                Log::channel('auto_assigning')->debug('Package sending lock exists, skipping '. now()->format('Y-m-d H:i:s'), [
                    'package_id' => $package->id,
                ]);
            }
        }

        Log::channel('auto_assigning')->debug('package:send command completed');
        return 0;
    }
}