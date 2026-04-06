<?php
namespace App\Repositories;

use App\Captain;
use App\Client;
use App\ClientShop;
use App\Interfaces\StreamLineInterface;
use App\Order;
use App\OrderStatus;
use App\PackageDeliveryRequest;
use App\Region;
use Closure;
use Illuminate\Http\Request;

class StreamLineInterfaceRepository implements StreamLineInterface
{
    public function getAreas()
    {
        return Region::select('id', 'quadrant_id', 'name')->belongsToMe()->toBase()->get();
    }
    public function getClients()
    {
        return Client::select('id', 'user_id')->with('user:name,id')->belongsToMe()->isActive()->get();
    }
    public function getBaseClientShops()
    {
        return ClientShop::select('id', 'client_id', 'name')->belongsToMe()->isActive()->get();
    }

    public function getClientShops(Request $request)
    {
        return ClientShop::query()
            ->withLogo()
            ->withClient()
            ->when($request->get('status', OrderStatus::NEW_ORDER), function ($query, $status) use ($request) {
                $query->whereHas('orders', function ($query) use ($status, $request) {
                    $query->belongsToMe();
                    $search = $request->get('search');

                    $query
                        ->when(! $search && (! $request->has('has_client_chat') || ! $request->get('has_client_chat')) && (! $request->has('scheduled') || ! $request->get('scheduled')), function ($query) {
                            $query->where(function ($query) {
                                $query->where([['delivery_type', '=', 'Scheduled'], ['dispatch_at', '<=', now()->format('Y-m-d H:i:s')]])->orWhere('delivery_type', '=', 'Fast');
                            });
                        })
                        ->when(! $search && $request->get('status', OrderStatus::NEW_ORDER) && ! ($request->has('has_client_chat') && $request->get('has_client_chat')), function ($query) use ($request) {
                            $status = $request->get('status', OrderStatus::NEW_ORDER);
                            $query->when($status && empty(array_diff([OrderStatus::NEW_ORDER, OrderStatus::ORDER_PACKAGE, OrderStatus::ASSIGN_ATTEMPTS], is_array($status) ? $status : [$status])), function ($query) {
                                $query->whereHas('shop', function ($query) {
                                    $query->where('auto_assignable', 1);
                                });
                            });
                            $query->when($status && empty(array_diff(is_array($status) ? $status : [$status], [OrderStatus::NEW_ORDER])) && (! $request->has('scheduled') || ! $request->get('scheduled')), function ($query) {
                                $query->whereHas('shop', function ($query) {
                                    $query->where('auto_assignable', 0);
                                });
                            });
                            if (is_array($status)) {
                                $query->whereIn('status_id', $status);
                                return;
                            }
                            $query->whereStatusId($status);
                        })
                        ->when(! $search && $request->has('has_client_chat') && $request->get('has_client_chat'), function ($query) {
                            $query->has('openComplaint');
                        })
                        ->when($request->get('search'), function ($query, $search) {
                            $query
                                ->where(function ($q) use ($search) {
                                    $q->where('orders.client_order_id', 'like', '%' . $search . '%')
                                        ->orWhere('orders.id', 'like', '%' . $search . '%')
                                        ->orWhere('orders.customer_number', 'like', '%' . $search . '%')
                                        ->orWhereHas('client.user', function ($q) use ($search) {
                                            $q->where('users.name', 'like', '%' . $search . '%');
                                        })
                                        ->orWhereHas('shop', function ($q) use ($search) {
                                            $q->where('client_shops.name', 'like', '%' . $search . '%');
                                        })
                                        ->orWhereHas('shop.region', function ($q) use ($search) {
                                            $q->where('regions.name', 'like', '%' . $search . '%');
                                        });
                                })
                                ->whereNotIn('status_id', OrderStatus::FINISHED);
                        })
                        ->when(! $search && $request->filled('client_id'), function ($query) use ($request) {
                            $clientIds = $request->get('client_id');
                            $query->whereIn('orders.client_id', (array) $clientIds);
                        })
                        ->when(! $search && $request->filled('shop_id'), function ($query) use ($request) {
                            $shopIds = $request->get('shop_id');
                            $query->whereIn('orders.shopname', (array) $shopIds);
                        })
                        // ->when(!$search && $request->get('client_id'), function ($query) use ($request) {
                        //     $query->where('client_shops.client_id', $request->get('client_id'));
                        // })
                        // ->when(!$search && $request->get('shop_id'), function ($query) use ($request) {
                        //     $query->whereShopname($request->get('shop_id'));
                        // })
                        ->when(! $search && $request->get('in_status'), function ($query) use ($request) {
                            $query->whereStatusId($request->get('in_status'));
                        })
                        ->when(! $search && $request->get('region'), function ($query) use ($request) {
                            $region = $request->get('region');
                            $query->whereHas('shop.region.quadrant', function ($query) use ($region) {
                                $region = is_array($region) ? $region : [$region];
                                $query->whereIn('quadrants.id', $region);
                            });
                        })
                        ->when(! $search && $request->get('area'), function ($query) use ($request) {
                            $area = $request->get('area');
                            $query->whereHas('shop.region', function ($query) use ($area) {
                                $area = is_array($area) ? $area : [$area];
                                $query->whereIn('regions.id', $area);
                            });
                        })
                        ->when(! $search && $request->has('scheduled') && $request->get('scheduled'), function ($query) {
                            $query->where('delivery_type', 'Scheduled');
                        });
                });
            })
            ->withCount('processingOrders')
            ->with('processingOrders:id,client_order_id,status_id,shopname', 'processingOrders.progress:id,name')
            ->get();
    }

    public function getStreamLineOrders(Request $request, $ticket_type)
    {
        $status = $request->get('status', null);

        $statuses = $request->get('status', [OrderStatus::NEW_ORDER]);
        $statuses = is_array($statuses) ? $statuses : [$statuses];
        $search   = $request->get('search');

        $auto_assignable = $status && empty(array_diff([OrderStatus::NEW_ORDER, OrderStatus::ORDER_PACKAGE, OrderStatus::ASSIGN_ATTEMPTS], is_array($status) ? $status : [$status]));

        return Order::query()
            ->belongsToMe()
            ->select('orders.id', 'orders.client_order_id', 'orders.captain_id', 'orders.dispatch_at', 'orders.status_id', 'orders.created_at', 'orders.location', 'orders.amount', 'orders.delivery_date', 'orders.delivery_type', 'orders.delivery_payment_mode', 'orders.shopname', 'orders.shop_to_delivery_km', 'orders.scheduled_delivery_time_slot_id', 'orders.customer_number')
            ->with('progress', 'shop', 'payment')
            ->withShop()
            ->withShopRegionAndZone()
            ->withLastLocation()
            ->withOpenTicket($ticket_type)
            ->withClient()
            ->withCaptain()
            ->when(! $search && (! $request->has('has_client_chat') || ! $request->get('has_client_chat')) && (! $request->has('scheduled') || ! $request->get('scheduled')), function ($query) {
                $query->where(function ($query) {
                    $query->where([['delivery_type', '=', 'Scheduled'], ['dispatch_at', '<=', now()->format('Y-m-d H:i:s')]])->orWhere('delivery_type', '=', 'Fast');
                });
            })
            ->when(! $search && $request->get('status', OrderStatus::NEW_ORDER) && ! ($request->has('has_client_chat') && $request->get('has_client_chat')), function ($query) use ($request, $auto_assignable) {
                $status = $request->get('status', OrderStatus::NEW_ORDER);
                $query->when($auto_assignable, function ($query) {
                    $query
                        ->whereHas('shop', function ($query) {
                            $query->where('auto_assignable', 1);
                        })
                        ->withAssignAttempts();
                });
                $query->when($status && empty(array_diff(is_array($status) ? $status : [$status], [OrderStatus::NEW_ORDER])) && (! $request->has('scheduled') || ! $request->get('scheduled')), function ($query) {
                    $query->whereHas('shop', function ($query) {
                        $query->where('auto_assignable', 0);
                    });
                });
                if (is_array($status)) {
                    $query->whereIn('status_id', $status);
                    return;
                }
                $query->whereStatusId($status);
            })
            ->when(! $search && $request->has('has_client_chat') && $request->get('has_client_chat'), function ($query) {
                $query->has('openComplaint');
            })
            ->when($request->get('search'), function ($query, $search) {
                $query
                    ->where(function ($q) use ($search) {
                        $q->where('orders.client_order_id', 'like', '%' . $search . '%')
                            ->orWhere('orders.id', 'like', '%' . $search . '%')
                            ->orWhere('orders.customer_number', 'like', '%' . $search . '%')
                            ->orWhereHas('client.user', function ($q) use ($search) {
                                $q->where('users.name', 'like', '%' . $search . '%');
                            })
                            ->orWhereHas('shop', function ($q) use ($search) {
                                $q->where('client_shops.name', 'like', '%' . $search . '%');
                            })
                            ->orWhereHas('shop.region', function ($q) use ($search) {
                                $q->where('regions.name', 'like', '%' . $search . '%');
                            });
                    })
                    ->whereNotIn('status_id', OrderStatus::FINISHED);
            })
            ->when(! $search && $request->filled('client_id'), function ($query) use ($request) {
                $clientIds = $request->get('client_id');
                $query->whereIn('orders.client_id', (array) $clientIds);
            })
            ->when(! $search && $request->filled('shop_id'), function ($query) use ($request) {
                $shopIds = $request->get('shop_id');
                $query->whereIn('orders.shopname', (array) $shopIds);
            })
            ->when(! $search && $request->get('in_status'), function ($query) use ($request) {
                $query->whereStatusId($request->get('in_status'));
            })
            ->when(! $search && $request->get('region'), function ($query) use ($request) {
                $region = $request->get('region');
                $query->whereHas('shop.region.quadrant', function ($query) use ($region) {
                    $region = is_array($region) ? $region : [$region];
                    $query->whereIn('quadrants.id', $region);
                });
            })
            ->when(! $search && $request->get('area'), function ($query) use ($request) {
                $area = $request->get('area');
                $query->whereHas('shop.region', function ($query) use ($area) {
                    $area = is_array($area) ? $area : [$area];
                    $query->whereIn('regions.id', $area);
                });
            })
            ->when(! $search && $request->has('scheduled') && $request->get('scheduled'), function ($query) {
                $query->where('delivery_type', 'Scheduled');
            })
            ->orderBy('orders.id')
            ->get()
            ->map(function ($order) {
                $order->time_left            = $order->endTime();
                $order->time_start           = $order->startTime();
                $order->created_formatted_at = $order->created_at->format('d-m-Y h:i:s A');
                $order->status_updated_at    = $order->delivery_date->format('h:i:s A');
                return $order;
            })
            ->when(! $auto_assignable, function ($collection) {
                return $collection->sortBy(function ($value) {
                    return $value->time_left->timestamp;
                });
            })
            ->values();
    }

    public function generateStreamLineStatistics()
    {
        return [
            'new_orders'            => [
                'class' => 'sl-status-c1',
                'count' => Order::query()
                    ->belongsToMe()
                    ->whereHas('shop', function ($query) {
                        $query->where('auto_assignable', 0);
                    })
                    ->where(function ($query) {
                        $query->where([['delivery_type', '=', 'Scheduled'], ['dispatch_at', '<=', now()->format('Y-m-d H:i:s')]])->orWhere('delivery_type', '=', 'Fast');
                    })
                    ->where('status_id', OrderStatus::NEW_ORDER)
                    ->count(),
            ],
            'auto_assign_orders'    => [
                'class' => 'sl-status-c2',
                'count' => Order::query()
                    ->belongsToMe()
                    ->where(function ($query) {
                        $query->where([['delivery_type', '=', 'Scheduled'], ['dispatch_at', '<=', now()->format('Y-m-d H:i:s')]])->orWhere('delivery_type', '=', 'Fast');
                    })
                    ->whereHas('shop', function ($query) {
                        $query->where('auto_assignable', 1);
                    })
                    ->whereIn('status_id', [OrderStatus::NEW_ORDER, OrderStatus::ORDER_PACKAGE, OrderStatus::ASSIGN_ATTEMPTS])
                    ->count(),
            ],
            'on_going_orders'       => [
                'class' => 'sl-status-c3',
                'count' => Order::query()
                    ->belongsToMe()
                    ->whereIn('status_id', [OrderStatus::ACCEPT, OrderStatus::WAITING_FOR_ACCEPTING, OrderStatus::START_RIDE, OrderStatus::REACHED_SHOP, OrderStatus::PICKED, OrderStatus::PICKED_UP, OrderStatus::SHIPPED, OrderStatus::REACHED_DESTINATION, OrderStatus::REROUTED])
                    ->count(),
            ],
            'client_chat_orders'    => [
                'class' => 'sl-status-c4',
                'count' => Order::query()->belongsToMe()->has('openComplaint')->count(),
            ],
            'ticket_orders'         => [
                'class' => 'sl-status-c5',
                'count' => Order::query()
                    ->belongsToMe()
                    ->whereIn('status_id', [OrderStatus::TICKET_RAISED])
                    ->count(),
            ],
            'pending_orders'        => [
                'class' => 'sl-status-c6',
                'count' => Order::query()->belongsToMe()->where('status_id', OrderStatus::PENDING)->count(),
            ],
            'client_return_orders'  => [
                'class' => 'sl-status-c7',
                'count' => Order::query()->belongsToMe()->where('status_id', OrderStatus::RETURN_TO_CLIENT)->count(),
            ],
            'cancel_request_orders' => [
                'class' => 'sl-status-c8',
                'count' => Order::query()->belongsToMe()->where('status_id', OrderStatus::REQUEST_FOR_CANCEL)->count(),
            ],
            'schedule_orders'       => [
                'class' => 'sl-status-c9',
                'count' => Order::query()
                    ->belongsToMe()
                    ->where([['delivery_type', '=', 'Scheduled']])
                    ->where('status_id', OrderStatus::NEW_ORDER)
                    ->count(),
            ],
        ];
    }

    public function isCaptainAssignable(?Order $order): bool
    {
        if (! $order) {
            return true;
        }

        if ($order->reassign_disable) {
            return false;
        }
        if ($order && $order->delivery_type == Order::DELIVERY_TYPE_FAST && $order->shop->dispatchRuleForExpress?->isManualAssignTempDisabled() && $order->assign_attempts_count < $order->shop->dispatchRuleForExpress?->manual_attempts && $order->created_at->diffInMinutes(now()) < $order->shop->dispatchRuleForExpress?->manual_time_limit) {
            return false;
        }

        if ($order && $order->reassign_disable) {
            return false;
        }

        return true;
    }

    public function getCaptains(Request $request, ?Order $order)
    {
        $region               = $request->get('region');
        $area                 = $request->get('area');
        $employment_type      = $request->get('employment_type');
        $company              = $request->get('company');
        $filter_captain       = $request->get('captain', false);
        $filter_captain_state = $request->get('show', false);
        $zero_delivered       = $request->get('zero_delivered', false);

        return Captain::query()
            ->select('captains.id', 'captains.phone_number', 'captain_employment_type_id', 'captains.auto_assign_priority_id')
            ->active()
            ->withName()
            ->withVehicleType()
            ->with('currentShift', 'location:captain_id,latitude,longitude', 'regions:name,id', 'currentOrder:id,client_id,client_order_id,shopname,captain_id,location,status_id', 'currentOrder.client:id,user_id', 'currentOrder.client.user:id,name', 'currentOrder.shop:id,name', 'currentOrder.progress:id,name', 'document', 'employmentType:id,name', 'company')
            ->withCount('currentOrder')
            ->withCount([
                'deliveredOrders' => function ($query) {
                    // Business day calculation: 06:00:00 today to 05:59:59 tomorrow
                    $now = now();

                    if ($now->format('H:i:s') < '06:00:00') {
                        // If current time is before 6 AM, we're still in yesterday's business day
                        $businessDayStart = $now->copy()->subDay()->setTime(6, 0, 0);
                        $businessDayEnd   = $now->copy()->setTime(5, 59, 59);
                    } else {
                        // If current time is 6 AM or later, we're in today's business day
                        $businessDayStart = $now->copy()->setTime(6, 0, 0);
                        $businessDayEnd   = $now->copy()->addDay()->setTime(5, 59, 59);
                    }

                    $query->whereBetween('delivery_date', [$businessDayStart, $businessDayEnd])->where('status_id', OrderStatus::DELIVERED);
                },
            ])
            ->when($area, function ($query, $area) {
                $query->whereHas('regions', function ($query) use ($area) {
                    $query->where('regions.id', $area);
                });
            })
            ->when($region, function ($query, $region) {
                $query->whereHas('regions.quadrant', function ($query) use ($region) {
                    $query->where('quadrants.id', $region);
                });
            })
            ->when($employment_type, function ($query, $employment_type) {
                $query->where('captains.captain_employment_type_id', $employment_type);
            })
            ->when($company, function ($query, $company) {
                $query->whereHas('company', function ($query) use ($company) {
                    $query->where('third_party_logistic_companies.id', $company);
                });
            })
            ->when($request->get('search', false), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query
                        ->whereHas('user', function ($query) use ($search) {
                            $query->where('name', 'like', '%' . $search . '%');
                            $query->orWhere('email', 'like', '%' . $search . '%');
                        })
                        ->orWhere('phone_number', 'like', $search . '%')
                        ->orWhere('iqama_number', 'like', $search . '%');
                });
            })
            ->when($order, function ($query) use ($order) {
                // dd($order->region_id ?? $order->shop->zone->region_id ?? 0);
                $query->whereHas('regions', function ($query) use ($order) {
                    $query->where('region_id', $order->region_id ?? ($order->shop->zone->region_id ?? 0));
                });

                $query->when($order->captain_id, function ($query) use ($order) {
                    // Order the specific captain first
                    $query->orderByRaw('CASE WHEN captains.id = ? THEN 0 ELSE 1 END', [$order->captain_id]);
                });

                $query->withCurrentLocationDistance($order->shop->location)->having('distance', '<=', 10)->orderBy('distance', 'asc');
            })
            ->when($filter_captain, function ($query, $filter_captain) {
                $query->where('captains.id', $filter_captain);
            })
            ->when($zero_delivered === 'true', function ($query) {
                $query->whereDoesntHave('deliveredOrders', function ($query) {
                    $query->whereDate('delivery_date', today())->where('status_id', OrderStatus::DELIVERED);
                });
            })
            ->when($filter_captain_state, function ($query, $captain_state) {
                if ($captain_state === 'all') {
                    $query->where(function ($query) {
                        $query->onlineFree();
                        $query->orWhereHas('currentOrder');
                    });
                }

                if ($captain_state === 'free') {
                    $query->onlineFree();
                }

                if ($captain_state === 'busy') {
                    $query->whereHas('currentOrder');
                }

                if ($captain_state === 'no_update') {
                    $query->idle();
                }

                if ($captain_state === 'offline') {
                    $query->offline();
                }
            })
            ->when($order && in_array($order->status_id, [OrderStatus::ACCEPT, OrderStatus::START_RIDE]), function ($query) use ($order) {
                $query->withCaptainArrivalTimeAtStore($order);
            })
            ->when($order && in_array($order->status_id, [OrderStatus::SHIPPED, OrderStatus::REACHED_DESTINATION]), function ($query) use ($order) {
                $query->withCaptainArrivalTimeAtDestination($order);
            })
            ->orderBy('name')
            ->get();
    }

    public function findOrder(int $orderId, ?Closure $closure = null)
    {
        $query = Order::query();

        if ($closure) {
            $closure($query);
        }

        return $query->find($orderId);

    }

    public function getCaptainBaseQuery(Request $request)
    {
        $region          = $request->get('region');
        $area            = $request->get('area');
        $employment_type = $request->get('employment_type');
        $company         = $request->get('company');
        $filter_captain  = $request->get('captain', false);
        $zero_delivered  = $request->get('zero_delivered', false);
        return Captain::active()
            ->when($area, fn($q) => $q->whereHas('regions', fn($q) => $q->where('regions.id', $area)))
            ->when($zero_delivered === 'true', fn($q) => $q->whereDoesntHave('deliveredOrders', fn($q) => $q->whereDate('delivery_date', today())->where('status_id', OrderStatus::DELIVERED)))
            ->when($region, fn($q) => $q->whereHas('regions.quadrant', fn($q) => $q->where('quadrants.id', $region)))
            ->when($employment_type, fn($q) => $q->where('captains.captain_employment_type_id', $employment_type))
            ->when($company, fn($q) => $q->whereHas('company', fn($q) => $q->where('third_party_logistic_companies.id', $company)))
            ->when(
                $request->get('search'),
                fn($q, $search) => $q->where(
                    fn($q) => $q
                        ->whereHas('user', fn($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                        ->orWhere('phone_number', 'like', "{$search}%")
                        ->orWhere('iqama_number', 'like', "{$search}%"),
                ),
            )
            ->when($filter_captain, fn($q) => $q->where('captains.id', $filter_captain));
    }

    public function findOrderWithDetails($orderId)
    {
        return $this->findOrder($orderId, function ($query) {
            $query
                ->select([
                    'id',
                    'client_order_id',
                    'client_id',
                    'shopname',
                    'captain_id',
                    'code',
                    'delivery_payment_mode',
                    'shop_to_delivery_km',
                    'captain_to_shop_km',
                    'delivery_date',
                    'delivery_type',
                    'scheduled_delivery_time_slot_id',
                    'customer_name',
                    'customer_number',
                    'address',
                    'location',
                    'email',
                    'note',
                    'status_id',
                    'created_at',
                ])
                ->withAssignAttempts()
                ->with([
                    'client:id,user_id',
                    'client.user:id,name',
                    'shop:id,name',
                    'captain:id,user_id,phone_number',
                    'captain.user:id,name',
                    'progress:id,name',
                    'timeSlot:id,name',
                    'addresses:id,order_id,address,latitude,longitude',
                    'notes:id,order_id,user_id,note,created_at',
                    'notes.user:id,name',
                    'notes.user.employeeClient:id,user_id',
                    'notes.user.employeeClient.user:id,name',
                    'logsExecpt',
                ]);
        });
    }

    public function getTodayCaptainStats(int $captainId): array
    {
        $baseQuery = PackageDeliveryRequest::query()
            ->today()
            ->whereNotNull('package_orders.order_id')
            ->leftJoin('packages', 'packages.id', '=', 'package_delivery_requests.package_id')
            ->leftJoin('package_orders', 'package_orders.package_id', '=', 'packages.id')
            ->leftJoin('orders', 'orders.id', '=', 'package_orders.order_id');

        $totalOrders = (clone $baseQuery)
            ->where('package_delivery_requests.captain_id', $captainId)
            ->distinct('package_orders.order_id')
            ->count('package_orders.order_id');

        $acceptedOrders = (clone $baseQuery)
            ->where('packages.captain_id', $captainId)
            ->distinct('package_orders.order_id')
            ->count('package_orders.order_id');

        $declinedOrders = (clone $baseQuery)
            ->where('package_delivery_requests.captain_id', $captainId)
            ->whereNotNull('package_delivery_requests.declined_at')
            ->whereColumn('packages.captain_id', '<>', 'package_delivery_requests.captain_id')
            ->distinct('package_orders.order_id')
            ->count('package_orders.order_id');

        return [
            'total_orders'    => $totalOrders,
            'accepted_orders' => $acceptedOrders,
            'declined_orders' => $declinedOrders,
            'missed_orders'   => max(
                0,
                $totalOrders - ($acceptedOrders + $declinedOrders)
            ),
        ];
    }
}
