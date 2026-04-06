<?php

namespace App\Jobs;

use App\Captain;
use App\DispatchRule;
use App\Events\AutoAssignPackageAttempt;
use App\Order;
use App\OrderStatus;
use App\Package;
use App\PackageDeliveryRequest;
use App\PackageOrder;
use App\PackageRejectionReason;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Facades\App\Services\OrderStatusLog;
use App\Events\OrderStatusChanged;
use Illuminate\Support\Facades\Cache;


class BroadcastPackage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $package;
    protected $fallback = 1 * 50;

    public function __construct(Package $package)
    {
        $this->package = $package->load('shop.region');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::channel('auto_assigning')->debug('BroadcastPackage job started', [
            'package_id' => $this->package->id,
        ]);
        if ($this->package->orders()->count() == 0) {
            Log::channel('auto_assigning')->debug('No orders in package, exiting', [
                'package_id' => $this->package->id,
            ]);
            return;
        }

        $ordersData = $this->package->directOrders;
        $hasCritical = Order::query()
            ->whereIn(
                'id',
                $ordersData->pluck('id')->toArray()
            )
            ->withAssignAttempts()
            ->where('status_id', OrderStatus::ASSIGN_ATTEMPTS)
            ->havingRaw('last_assign_attempt_note LIKE ?', ['%CRITICAL%'])
            ->exists();


        Log::channel('auto_assigning')->info('has critical assign', [
            'package_id' => $this->package->id,
            'has_critical' => $hasCritical,
            'order_ids' => $ordersData->pluck('id')->toArray(),
        ]);

        $captains = $hasCritical ? $this->getCriticalAssignCaptain() : $this->captains();




        Log::channel('auto_assigning')->debug('Fetched captains for package', [
            'package_id' => $this->package->id,
            'captain_count' => $captains->count(),
            'critical' => $hasCritical
        ]);

        if ($hasCritical && $captains->count() > 0) {

            $captain = $captains->first();


            if ($captain) {

                if (Captain::whereKey($captain->id)->onlineBusy()->exists()) {
                    Log::channel('auto_assigning')->warning('Captain went busy before assignment, skipping assignment', [
                        'package_id' => $this->package->id,
                        'captain_id' => $captain->id,
                    ]);
                    return;
                }

                // cache lock for 2 minutes
                if(Cache::has('critical_assign_captain_' . $captain->id)){
                    Log::channel('auto_assigning')->warning('Captain found in cache, skipping assignment', [
                        'package_id' => $this->package->id,
                        'captain_id' => $captain->id,
                    ]);
                    return;
                }else{
                     Cache::put('critical_assign_captain_' . $captain->id, true, 120);
                }

                $this->assignCaptain($this->package, $captain);

                  Log::channel('auto_assigning')->debug('Critical Assigning package to captain', [
                    'package_id' => $this->package->id,
                    'captain_id' => $captain->id,
                    'status' => 'completed'
                ]);
            }
        } else {
            foreach ($captains as $key => $captain) {
                Log::channel('auto_assigning')->debug('Sending package to captain', [
                    'package_id' => $this->package->id,
                    'captain_id' => $captain->id,
                ]);
                $this->send($captain);
            }

            event(new AutoAssignPackageAttempt($this->package, $captains));
        }
       

        Log::channel('auto_assigning')->debug('BroadcastPackage job completed', [
            'package_id' => $this->package->id,
        ]);
    }


    public function captains()
    {
        $package = $this->package;

        $region_id = isset($package->shop->region->id) ? $package->shop->region->id : null;

        Log::channel('auto_assigning')->debug('Captains query initiated', [
            'package_id' => $package->id,
            'region_id' => $region_id,
            'shop_id' => $package->shop_id,
            'client_shop_id' => $package->client_shop_id,
            'dispatch_rule_id' => $package->dispatch_rule_id,
            'dispatch_after' => $package->dispatch_after,
        ]);

        $captains = Captain::with('accessToken', 'user')
            ->onlineFree()
            ->whereHas('regions', function ($query) use ($region_id) {
                $query->where('regions.id', $region_id);
            })
            ->whereDoesntHave('packageRequests', function (Builder $query) {
                $query->where([['package_delivery_requests.sended_at', '>', now()->subSeconds($this->fallback)->format('Y-m-d H:i:s')], ['package_delivery_requests.declined_at', '=', null]]);
                $query->whereHas('package', function (Builder $query) {
                    $query->where('captain_id', null);
                });
            })
            ->whereRaw(
                "
                    (select (distance / 1000) from `captain_store` where captain_store.captain_id = captains.id && captain_store.client_shop_id = {$package->client_shop_id} order by id desc limit 1 )
                    <=
                    (
                        select `dispatch_notification_preferences`.`to_km` from `dispatch_notification_preferences`
                        where
                            now() >= TIMESTAMPADD(MINUTE, dispatch_notification_preferences.waiting_time, '" .
                ($package->dispatch_after ? now()->parse($package->dispatch_after->format('Y-m-d H:i:s')) : $package->created_at->format('Y-m-d H:i:s')) .
                "')
                            AND dispatch_notification_preferences.dispatch_rule_id = {$package->dispatch_rule_id}
                        order by `dispatch_notification_preferences`.`waiting_time` desc limit 1
                    )
                ",
            )
            ->orderByRaw(
                "
                                (
                                    select
                                        distance
                                    from `captain_store`
                                    where
                                        captain_store.captain_id = captains.id &&
                                        captain_store.client_shop_id = {$package->client_shop_id}
                                        order by id desc limit 1
                                ) ASC
                            ",
            )
            ->get();

        Log::channel('auto_assigning')->debug('Captains query result', [
            'package_id' => $package->id,
            'total_captains' => $captains->count(),
            'captain_ids' => $captains->pluck('id'),
        ]);

        return $captains;
    }

   
    public function sendForceAssignNotification($captain)
    {
        try {
            $accessToken = $captain->accessToken->fb_token;
            if (!$accessToken) {
                return;
            }

            $metadata = \App\Reminder::getNotificationMetadata(\App\Reminder::FORCE_ASSIGN);
            $data = [
                'priority' => 'High',
                'content_available' => true,
                'body' => __('app/notifications.new_order.body', ['package' => $this->package->id], $captain->user->language),
                'title' => __('app/notifications.new_order.title', [], $captain->user->language),
                'reminder_type' => \App\Reminder::FORCE_ASSIGN,
                'id' => $this->package->id,
                'orders_count' => $this->package->orders->count(),
                'accept_before' => now()->diffInSeconds(now()->addMinutes(\App\Captain::CAPTAIN_ACCEPTING_TIME_IN_MINUTES), false),
                'shopimage' => '',
                'sound' => $metadata['sound'],
                'android_channel_id' => $metadata['android_channel_id'],
                'mutable_content' => true,
            ];

            FCMSend::dispatch($data, $accessToken, null, $captain->firebaseVersion());

            return true;
        } catch (\Exception $e) {
            Log::channel('auto_assigning')->error('BroadcastPackage Force Assign job failed for captain', [
                'package_id' => $this->package->id,
                'captain_id' => $captain->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    public function send($captain)
    {
        try {
            $accessToken = $captain?->accessToken?->fb_token;
            $shop = $this->package->shop;
            if (!$accessToken) {
                return;
            }

            [$lat, $lon] = $shop->location;

            [$distance, $time] = $this->package->totalTravelling();

            $metadata = \App\Reminder::getNotificationMetadata(\App\Reminder::AUTO_ASSIGN);

            $data = [
                'reminder_type' => \App\Reminder::AUTO_ASSIGN,
                'priority' => 'High',
                'content_available' => true,
                'auto_assign' => 'type',
                'body' => __('app/notifications.package.body', ['package' => $this->package->id], $captain->user->language),
                'title' => __('app/notifications.package.title', [], $captain->user->language),
                'shopimage' => '',
                'shopname' => $shop->name,
                'shop_address' => $shop->address,
                'package_picked_with_in' => $this->package->dispatch_with_in,
                'shop_lat' => $lat,
                'shop_lan' => $lon,
                'package_id' => $this->package->id,
                'shop_phone' => $shop->shop_admin_mobile,
                'delivery_boy_name' => $captain->user->name,
                'no_of_orders_in_package' => $this->package->orders->count(),
                'total_distance_in_km' => $distance / 1000,
                'total_duration' => secondsToTime($time),
                'sound' => $metadata['sound'],
                'android_channel_id' => $metadata['android_channel_id'],
                // "content_available" => true,
                'id' => $this->package->id,
                'orders_count' => $this->package->orders->count(),
                // "accept_before" => now()->diffInSeconds(now()->addMinutes(\App\Captain::CAPTAIN_ACCEPTING_TIME_IN_MINUTES), false),
                'accept_before' => now()->diffInSeconds(now()->addSeconds(20), false),
                'mutable_content' => true,
                'rejection_reasons' => PackageRejectionReason::all(),
            ];

            $delay = now()->addSeconds((($captain->auto_assign_priority_id ?? 1) - 1) * 5);
            MethodToQueue::dispatch(\App\Events\AutoAssignPackageAvailable::class, 'dispatch', [$this->package, $captain])->delay($delay);
            FCMSend::dispatch(
                $data,
                $accessToken,
                [
                    'class' => BroadcastPackage::class,
                    'method' => 'runAfterDispatch',
                    'argument' => [$this->package->id, $captain->id, now()->addSeconds((($captain->auto_assign_priority_id ?? 1) - 1) * 5)],
                ],
                $captain->firebaseVersion(),
                [
                    'class' => BroadcastPackage::class,
                    'method' => 'beforeDispatch',
                    'argument' => [$this->package->id, $captain->id],
                ],
            )->delay($delay);

              Log::channel('auto_assigning')->debug('Auto assign notification send', [
                   'status' => 'done'
                ]);

            return true;
        } catch (\Exception $e) {
            Log::channel('auto_assigning')->error('auto assign notification sending failed', [
                'package_id' => $this->package->id,
                'captain_id' => $captain->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    public static function beforeDispatch($package_id, $captain_id)
    {
        Log::channel('auto_assigning')->debug('BroadcastPackage beforeDispatch started', [
            'package_id' => $package_id,
            'captain_id' => $captain_id,
        ]);

        $package_orders = PackageOrder::where('package_id', $package_id)->get();
        if ($package_orders->count() == 0) {
            return true;
        }

        $has_any_inprogress_orders = PackageOrder::query()
            ->where('package_id', $package_id)
            ->whereHas('order', function ($query) {
                $query->whereNotIn('status_id', [OrderStatus::NEW_ORDER, OrderStatus::ORDER_PACKAGE, OrderStatus::ASSIGN_ATTEMPTS]);
            })
            ->count();

        if ($has_any_inprogress_orders > 0) {
            return true;
        }

        if (Package::find($package_id)->captain_id != null) {
            return true;
        }

        return false;
    }

    public static function runAfterDispatch($package_id, $captain_id, $dispatched_at = null)
    {
        Log::channel('auto_assigning')->debug('BroadcastPackage runAfterDispatch started', [
            'package_id' => $package_id,
            'captain_id' => $captain_id,
            'dispatched_at' => $dispatched_at,
        ]);

        $package = Package::find($package_id);

        $delivery_request = PackageDeliveryRequest::create([
            'package_id' => $package_id,
            'captain_id' => $captain_id,
            'sended_at' => $dispatched_at ?? now(),
        ]);

        $package->orders->each(function ($order) use ($delivery_request) {
            DB::table('order_package_delivery_request')->insert([
                'order_id' => $order->order_id,
                'package_delivery_request_id' => $delivery_request->id,
            ]);
        });
    }

    public function assignCaptain($package, $captain)
    {
        try {
            $logged_user = config('app.system_user');
            $ordersData = $package->directOrders;
            Log::channel('auto_assigning')->debug('Assigning captain to package', [
                'package_id' => $package->id,
                'captain_id' => $captain->id,
                'order_count' => $ordersData->count(),
            ]);

            if ($ordersData->isEmpty() || !$captain?->id) {
                //return response()->json(['status' => 'error', 'message' => 'Invalid input'], 422);
                Log::channel('auto_assigning')->error('No orders found or invalid captain ID', [
                    'package_id' => $package->id,
                    'captain_id' => $captain?->id,
                ]);
                return;
            }

            if ($package->captain_id) {
                Log::channel('auto_assigning')->warning('Package already assigned to a captain, skipping assignment', [
                    'package_id' => $package->id,
                    'existing_captain_id' => $package->captain_id,
                    'attempted_captain_id' => $captain->id,
                ]);
                return;
            }

            $note = $ordersData->pluck('id')->map(fn($id) => "#$id")->join(',');
            Log::channel('auto_assigning')->debug('Note: ', [
                'package_id' => $package->id,
                'captain_id' => $captain->id,
                'note' => $note,
            ]);

            $isDeliveryRequestExists = PackageDeliveryRequest::where('package_id', $package->id)
                ->where('captain_id', $captain->id)
                //->whereNull('declined_at') /// Need to check if this is required
                ->exists();
            if (!$isDeliveryRequestExists) {
                Log::channel('auto_assigning')->debug('No existing delivery request found, creating new one', [
                    'package_id' => $package->id,
                    'captain_id' => $captain->id,
                ]);
                $delivery_request = new PackageDeliveryRequest(['sended_at' => now()]);
                $delivery_request->captain()->associate($captain)->save();
                $delivery_request->package()->associate($package)->save();

                 Log::channel('auto_assigning')->debug('PackageDeliveryRequest created', [
                'package_id' => $package->id,
                'captain_id' => $captain->id,
                'delivery_request_id' => $delivery_request->id,
            ]);
            } 

           

            foreach ($ordersData as $key => $order) {
                DB::transaction(function () use ($order, $captain, $logged_user, $key, $package, $note) {
                    //$order->update(['captain_id' => $captain->id, 'status_id' => OrderStatus::WAITING_FOR_ACCEPTING, 'created_by' => $logged_user]);
                    // if(!in_array($order->status_id, [OrderStatus::ASSIGN_ATTEMPTS])) {
                    //     Log::channel('dispatch_rule_change')->warning('This Order has already been assigned to some other captain', ['order_id' => $order->id]);
                    //     return;
                    // }

                    // OrderStatusLog::log(OrderStatus::ORDER_PACKAGE, null, $order->id, null, $note, null, auth()->id());
                    OrderStatusLog::log(OrderStatus::ASSIGNED_TO, $captain->id, $order->id);
                    OrderStatusLog::log(OrderStatus::WAITING_FOR_ACCEPTING, null, $order->id);
                    OrderStatusLog::log(OrderStatus::ASSIGNED_BY, null, $order->id);

                    $order->update(['captain_id' => $captain->id, 'status_id' => OrderStatus::ACCEPT, 'created_by' => 0]);
                    OrderStatusLog::log(OrderStatus::ACCEPT, $captain->id, $order->id, null, null, null);

                    $package_order = new PackageOrder(['priority' => $key + 1, 'package_id' => $package->id]);
                    $is_in_package = PackageOrder::where('order_id', $order->id)->first();
                    if ($is_in_package) {
                        $is_in_package->delete();
                    }
                    $package_order->order()->associate($order)->save();
                    $package->orders()->save($package_order);

                    OrderStatusLog::updatePrestashopOrderStatus($order->id, OrderStatus::WAITING_FOR_ACCEPTING);
                });

                OrderStatusChanged::dispatch($order);
                // \App\Events\OrderAssignAttempt::dispatch($order);
                // $orderResult = Order::with('captain', 'client')->find($order->id);

                // $package = Package::with('directOrders.captain', 'directOrders.client')->find(1975647);
                // $this->notification($captain, $orderResult);
                // \App\Events\OrderAssignAttempt::dispatch($orderResult);
            }

            $package->captain_accepted_at = now();
            $package->captain()->associate($captain)->save();
            // $orderResult = Order::with('captain', 'client')->find($order->id);

            // $package = Package::with('directOrders.captain', 'directOrders.client')->find($package->id);
            // Log::channel('dispatch_rule_change')->debug('Package after assignment', [
            //     'package_id' => $package->id,
            // ]);
            $this->sendForceAssignNotification(captain: $captain);


            return;
        } catch (\Exception $e) {
            Log::channel('auto_assigning')->error('Error assigning captain to package', [
                'package_id' => $package->id,
                'captain_id' => $captain->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Optionally rethrow the exception or handle it as needed
        }
    }

    public function getCriticalAssignCaptain()
    {
        try {
            $package = $this->package;

            $regionId = $package->shop->region->id ?? null;
            $clientShopId = $package->client_shop_id;

            $dispatchRule = DispatchRule::find($package->dispatch_rule_id);

            $criticalRadius = $dispatchRule->critical_radius ?? 0;
            $maxOrderCount = $dispatchRule->min_completed_order_count ?? 0;
            $priorityId = $dispatchRule->critical_assign_priority ?? null;

            Log::channel('auto_assigning')->warning(
                'No notification preference matched. Applying critical preference.',
                ['used_critical_radius' => $criticalRadius]
            );

            // Reusable distance subquery
            $distanceSubQuery = "
            (
                SELECT (distance / 1000)
                FROM captain_store
                WHERE captain_store.captain_id = captains.id
                  AND captain_store.client_shop_id = ?
                ORDER BY id DESC
                LIMIT 1
            )
        ";

            return Captain::with(['accessToken', 'user'])
                ->onlineFree()
                ->whereHas('regions', fn($q) =>
                    $q->where('regions.id', $regionId))
                ->whereRaw("$distanceSubQuery <= ?", [
                    $clientShopId,
                    $criticalRadius,
                ])
                ->when(
                    $priorityId,
                    fn($q) => $q->where('auto_assign_priority_id', $priorityId)
                )
                ->withCount([
                    'todayFinishedOrders as today_finished_orders_count'
                ])
                ->having(
                    'today_finished_orders_count',
                    '<=',
                    $maxOrderCount
                )
                ->orderBy('today_finished_orders_count', 'asc')
                ->orderByRaw("$distanceSubQuery ASC", [$clientShopId])
                ->limit(1)
                ->get();



        } catch (\Exception $e) {

            Log::channel('auto_assigning')->error(
                'Error in auto-assigning process',
                [
                    'package_id' => $this->package->id,
                    'client_shop_id' => $this->package->client_shop_id,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return collect();
        }
    }




}
