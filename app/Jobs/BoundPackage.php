<?php

namespace App\Jobs;

use App\ClientShop;
use App\DeliveryType;
use App\Events\AutoAssignPackageBound;
use App\Order;
use App\OrderOrdersDistance;
use App\OrderStatus;
use App\Package;
use App\PackageOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BoundPackage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    protected $delivery_types = [
        Order::DELIVERY_TYPE_FAST,
        Order::DELIVERY_TYPE_SCHEDULE
    ];

    protected $delivery_type;

    protected $max_no_of_order = 10;
    protected $waiting_time_of_seed_order = 10;
    protected $radius_for_seed_orders = 10;

    protected $distances;
    protected $dispatch_rule;

    protected $package_updating;

    /**
     * Create a new job instance.
     */
    public function __construct($delivery_type)
    {
        $this->delivery_type = in_array($delivery_type, $this->delivery_types) ? $delivery_type : Order::DELIVERY_TYPE_FAST;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $delivery_type = $this->delivery_type;

        Log::channel('auto_assigning')->debug('Starting BoundPackage job', [
            'delivery_type' => $delivery_type,
        ]);

        $shops = ClientShop::with([
            'orders' => function ($query) use ($delivery_type) {
                $query->select('orders.*');
                $query->with('storeDistance');
                $query->readyToDispatch($delivery_type);
                $query->doesntHave('package'); 
            },
            'dispatchRuleForExpress',
            'dispatchRuleForSchedule'
        ])
            ->whereHas('orders', function ($query) use ($delivery_type) {
                $query->readyToDispatch($delivery_type);
                $query->doesntHave('package');
            })
            ->autoAssignable()
            ->get();

        Log::channel('auto_assigning')->debug('Fetched shops', [
            'shop_count' => $shops->count(),
        ]);

        foreach ($shops as $shop) {
            Log::channel('auto_assigning')->debug('Processing shop', [
                'shop_id' => $shop->id,
            ]);
            $this->process($shop);
        }

        Artisan::call('package:send');
        Log::channel('auto_assigning')->debug('Called package:send artisan command');
    }


    public function process($shop)
    {
        Log::channel('auto_assigning')->debug('Processing orders for shop', [
            'shop_id' => $shop->id,
            'orders_count' => $shop->orders->count(),
        ]);
        $orders = $shop->orders;
        $no_of_orders = $orders->count();

        if ($no_of_orders == 0) {
            return;
        }

        // has any packages waiting for seed orders

        if ($this->delivery_type == Order::DELIVERY_TYPE_SCHEDULE) {
            $dispatch_rule = $shop->dispatchRuleForSchedule ?? $shop->dispatchRuleForExpress;
        } else {
            $dispatch_rule = $shop->dispatchRuleForExpress;
        }

        if (!$dispatch_rule) {
            Log::channel('auto_assigning')->warning('No dispatch rule found for shop', [
                'shop_id' => $shop->id,
                'delivery_type' => $this->delivery_type,
            ]);
            return;
        }

        if ($dispatch_rule->status != 1 && now()->greaterThanOrEqualTo($dispatch_rule->status_applied_from) && now()->lessThanOrEqualTo($dispatch_rule->status_applied_to)) {
            Log::channel('auto_assigning')->warning('Assigned dispatch is disabled', [
                'shop_id' => $shop->id,
                'delivery_type' => $this->delivery_type,
                'dispatch_rule_id' => $dispatch_rule->id,
                'status' => $dispatch_rule->status,
                'status_applied_from' => $dispatch_rule->status_applied_from,
                'status_applied_to' => $dispatch_rule->status_applied_to,
            ]);
            return;
        }

        Log::channel('auto_assigning')->debug('Dispatch rule applied', [
            'shop_id' => $shop->id,
            'dispatch_rule_id' => $dispatch_rule->id,
        ]);

        $this->dispatch_rule = $dispatch_rule;

        $order_folder_accept = $dispatch_rule->can_create_order_folder;
        $isWithinCombineTimeFrame = $dispatch_rule->isWithinCombineTimeFrame();

        Log::channel('auto_assigning')->debug('isWithinCombineTimeFrame: ', [
            $isWithinCombineTimeFrame
        ]);

        $this->max_no_of_order = ($order_folder_accept && $isWithinCombineTimeFrame) ? $dispatch_rule->no_of_orders_in_folder : 1;
        $this->waiting_time_of_seed_order = ($order_folder_accept && $isWithinCombineTimeFrame) ? $dispatch_rule->waiting_time_from_first_order : -1;
        $this->radius_for_seed_orders = ($order_folder_accept && $isWithinCombineTimeFrame) ? $dispatch_rule->radius_for_seed_orders : 0;
        // $this->max_no_of_order = $order_folder_accept ? $dispatch_rule->no_of_orders_in_folder : 1;
        // $this->waiting_time_of_seed_order = $order_folder_accept ? $dispatch_rule->waiting_time_from_first_order : -1;
        // $this->radius_for_seed_orders = $order_folder_accept ? $dispatch_rule->radius_for_seed_orders : 0;

        $orders_id = $orders->pluck('id')->toArray();
        $this->distances = OrderOrdersDistance::where(function ($query) use ($orders_id) {
            $query->whereIn('to_order_id', $orders_id);
            $query->orWhereIn('from_order_id', $orders_id);
        })->orderBy('distance', 'asc')
            ->get();

        $this->combineOrders($shop, $orders);

        return;
    }

    public function getDistance($from_order, $to_order)
    {
        $distance = -1;
        $traveling_time = 0;

        $from_order_id = $from_order->id;
        $to_order_id = $to_order->id;
        // dd($this->distances, $from_order_id, $to_order_id);
        foreach ($this->distances as $distance_obj) {
            if (($distance_obj->from_order_id == $from_order_id && $distance_obj->to_order_id == $to_order_id) || ($distance_obj->from_order_id == $to_order_id && $distance_obj->to_order_id == $from_order_id)) {
                $distance = ($distance_obj->distance) / 1000;
                $traveling_time = $distance_obj->traveling_time;
                break;
            }
        }

        return $distance;
    }

    public function isSeedOrder($from_order, $order)
    {
        $distance = $this->getDistance($from_order, $order);
        if (empty($order->location) || $distance != -1 && $distance <= $this->radius_for_seed_orders) {
            return $order;
        }
        return null;
    }

    public function combineOrders($shop, $orders)
    {
        $waiting_list_packages = $this->getWaitingList($shop)->keys();

        while ($orders->isNotEmpty()) {
            $packages = collect();
            // has any packages waiting for seed orders
            if ($package_id = $waiting_list_packages->shift()) {
                $this->package_updating = Package::with('directOrders')->find($package_id);
                $packages = $this->package_updating->directOrders;
            } else {
                $this->package_updating = null;
            }
            $first_order = $packages->isEmpty() ? $orders->shift() : $packages->first();
            $packages = $packages->isEmpty() ? $packages->push($first_order) : $packages;

            foreach ($orders as $key => $order) {
                if ($packages->count() >= $this->max_no_of_order) {
                    break;
                }
                if ($combine_order = $this->isSeedOrder($first_order, $order)) {
                    $orders->forget($key);
                    $packages->push($combine_order);
                }
            }
            $this->bindPackage($shop, $packages);
        }
    }

    public function bindPackage($shop, $orders)
    {

        Log::channel('auto_assigning')->debug('Binding package', [
            'shop_id' => $shop->id,
            'orders' => $orders->pluck('id')->toArray(),
        ]);
        $first_order = $orders->first();

        $waiting_time = $this->waitingTime($first_order, $orders);

        if ($this->package_updating) {
            $package = $this->package_updating;
            $package->update(['dispatch_after' => $waiting_time]);
            Log::channel('auto_assigning')->debug('Updating existing package', [
                'package_id' => $package->id,
            ]);

            $this->waitingListRemove($shop, $package);
        } else {
            Log::channel('auto_assigning')->debug('Creating new package', [
                'shop_id' => $shop->id,
            ]);
            $package = Package::create([
                'client_shop_id' => $shop->id,
                'dispatch_with_in' => now()->addSeconds(120),
                'relaxation_time' => 120,
                'dispatch_after' => $waiting_time,
                'dispatch_rule_id' => $this->dispatch_rule->id,
            ]);
        }


        foreach ($orders as $key => $order) {
            $order = $order->fresh();
            if (!in_array($order->status_id, [OrderStatus::NEW_ORDER, OrderStatus::ORDER_PACKAGE, OrderStatus::ASSIGN_ATTEMPTS, OrderStatus::RESCHEDULED])) {
                continue;
            }

            PackageOrder::updateOrInsert(
                ['order_id' => $order->id],
                ['priority' => 1, 'package_id' => $package->id]
            );
        }

        event(new AutoAssignPackageBound($package));

        Log::channel('auto_assigning')->debug('Package bound and event fired', [
            'package_id' => $package->id,
        ]);

        if (count($orders) <= $this->max_no_of_order && $waiting_time && $waiting_time->isFuture()) {
            $this->putWaitingList($shop, $package, $waiting_time);
        }
    }

    public function waitingTime($waiting_from, $package_orders)
    {
        $waiting_time = ($package_orders->count() < $this->max_no_of_order) ?
            ($this->delivery_type == DeliveryType::SCHEDULES ? now()->parse($waiting_from->dispatch_at) : $waiting_from->created_at)->addSeconds($this->waiting_time_of_seed_order * 60) :
            now()->subMinute();
        return $waiting_time;
    }

    public function putWaitingList($shop, $package, $waiting_time = 0)
    {

        if ($waiting_time instanceof \Illuminate\Support\Carbon && $waiting_time->isPast()) {
            return;
        }

        $waiting_list = Cache::get('seed_order_waiting_' . $shop->id, []);
        $waiting_list[$package->id] = $waiting_time;
        Cache::forever('seed_order_waiting_' . $shop->id, $waiting_list);

        $this->waitingListUpdate($shop);
    }

    public function getWaitingList($shop)
    {
        $waiting_list = $this->waitingListUpdate($shop);
        return $waiting_list;
    }

    public function hasInWaitingList($shop, $package)
    {
        $waiting_list = $this->getWaitingList($shop);
        return $waiting_list->has($package->id);
    }

    public function waitingListRemove($shop, $package)
    {
        $waiting_list = $this->getWaitingList($shop);
        $waiting_list->forget($package->id);
        Cache::forever('seed_order_waiting_' . $shop->id, $waiting_list);
    }

    public function waitingListUpdate($shop)
    {
        $waiting_list = collect(Cache::get('seed_order_waiting_' . $shop->id, []))->sortBy(function ($value) {
            return $value;
        });
        $updated_waiting_list = $waiting_list->filter(function ($times) {
            return now()->parse($times)->isFuture();
        });

        Cache::forever('seed_order_waiting_' . $shop->id, $updated_waiting_list);

        return $updated_waiting_list;
    }
}
