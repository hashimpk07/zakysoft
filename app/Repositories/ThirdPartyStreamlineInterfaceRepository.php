<?php
namespace App\Repositories;

use App\Captain;
use App\ClientShop;
use App\Interfaces\ThirdPartyStreamlineInterface;
use App\Order;
use App\OrderStatus;
use App\PackageDeliveryRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ThirdPartyStreamlineInterfaceRepository implements ThirdPartyStreamlineInterface
{
    public function getOrders(Request $request, array $status)
    {
        return Order::query()
            ->belongsToMeOptimized()
            ->select('orders.id', 'orders.client_order_id', 'orders.captain_id', 'orders.dispatch_at', 'orders.status_id', 'orders.created_at', 'orders.location', 'orders.delivery_date', 'orders.delivery_type', 'orders.shopname', 'orders.shop_to_delivery_km', 'orders.scheduled_delivery_time_slot_id')
            ->with([
                'progress',
                'captain:id',
                'captain.location:id,captain_id,latitude,longitude',
                'openComplaint:id,order_id'
            ])
            ->withShop()
            ->withShopRegionAndZone()
            ->withLastLocation()
            ->withClient()
            ->withCaptain()
            ->orderBy('orders.id')
            ->belongsTo3pl(company_id: $request->company_id_3pl)
            ->when($status, function ($query, $status) {
                if (is_array($status)) {
                    $query->whereIn('status_id', $status);
                } else {
                    $query->where('status_id', $status);
                }
            })
            ->when($request->get('search', false), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('client_order_id', 'like', '%' . $search . '%');
                    $query->orWhere('shopname', 'like', '%' . $search . '%');
                });
            })
            ->get()
            ->map(function ($order) {
                $order->time_left = $order->endTime();
                $order->created_formatted_at = $order->created_at->format('d-m-Y h:i:s A');
                return $order;
            });
    }

    public function getShops(Request $request)
    {
        $status = $request->get('status', [...OrderStatus::ON_GOING_ORDER, ...[OrderStatus::REROUTED, OrderStatus::TICKET_RAISED]]);

        return ClientShop::query()
            ->withLogo()
            ->withClient()
            ->whereHas('orders', function ($query) use ($status, $request) {
                $query
                    ->when($status, function ($query, $status) {
                        if (is_array($status)) {
                            $query->whereIn('status_id', $status);
                        } else {
                            $query->where('status_id', $status);
                        }
                    })
                    ->when($request->get('search', false), function ($query, $search) {
                        $query->where(function ($query) use ($search) {
                            $query->where('client_order_id', 'like', '%' . $search . '%');
                            $query->orWhere('shopname', 'like', '%' . $search . '%');
                        });
                    })
                    ->belongsTo3pl();
            })
            ->get();
    }

    public function orderCountByStatus(int|array $statuses, int|string $company_id): int
    {
        return Order::belongsTo3pl(company_id: $company_id)->when(is_array($statuses), fn($query) => $query->whereIn('status_id', $statuses), fn($query) => $query->where('status_id', $statuses))->count();
    }

    public function findOrderById($id)
    {
        return Order::with('shop')->find($id);
    }

    public function getCaptains(Request $request, ?Order $order = null)
    {
        $region = $request->get('region');
        $area = $request->get('area');
        $employment_type = $request->get('employment_type');
        $company = $request->get('company');
        $filter_captain = $request->get('captain', false);
        $filter_captain_state = $request->get('show', false);

        return Captain::query()
            ->select('captains.id', 'captains.phone_number', 'captain_employment_type_id', 'nationality_id')
            ->active()
            ->withName()
            ->withVehicleType()
            ->with('currentShift', 'location:captain_id,latitude,longitude', 'regions:name,id', 'currentOrder:id,client_id,client_order_id,shopname,captain_id', 'currentOrder.client:id,user_id', 'currentOrder.client.user:id,name', 'currentOrder.shop:id,name', 'document', 'employmentType:id,name', 'company', 'nationality:id,name')
            ->withCount('currentOrder')
            ->withCount([
                'deliveredOrders' => function ($query) {
                    $query->whereDate('delivery_date', today());
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
                $query->whereHas('regions', function ($query) use ($order) {
                    $query->where('region_id', $order->region_id ?? ($order->shop->zone->region_id ?? 0));
                });

                $query->when($order->captain_id, function ($query) use ($order) {
                    // Order the specific captain first
                    $query->orderByRaw('CASE WHEN captains.id = ? THEN 0 ELSE 1 END', [$order->captain_id]);
                });

                $query->withCurrentLocationDistance($order->shop->location)->orderBy('distance', 'asc');
            })
            ->when($filter_captain, function ($query, $filter_captain) {
                $query->where('captains.id', $filter_captain);
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
            ->orderBy('name')
            ->belongsTo3pl(company_id: $company)
            ->get();
    }

    public function getCaptainStats($captainId)
    {
        $total_orders = PackageDeliveryRequest::query()->leftJoin('packages', 'packages.id', '=', 'package_delivery_requests.package_id')->leftJoin('package_orders', 'package_orders.package_id', '=', 'packages.id')->leftJoin('orders', 'orders.id', '=', 'package_orders.order_id')->where('package_delivery_requests.captain_id', $captainId)->today()->whereNotNull('order_id')->groupBy('order_id')->select(DB::raw('count(*) as count'))->get()->count();
        $total_accepted = PackageDeliveryRequest::query()->leftJoin('packages', 'packages.id', '=', 'package_delivery_requests.package_id')->leftJoin('package_orders', 'package_orders.package_id', '=', 'packages.id')->leftJoin('orders', 'orders.id', '=', 'package_orders.order_id')->where('packages.captain_id', $captainId)->today()->whereNotNull('package_orders.order_id')->groupBy('package_orders.order_id')->select(DB::raw('count(*) as count'))->get()->count();

        $total_declined = PackageDeliveryRequest::query()
            ->leftJoin('packages', 'packages.id', '=', 'package_delivery_requests.package_id')
            ->leftJoin('package_orders', 'package_orders.package_id', '=', 'packages.id')
            ->leftJoin('orders', 'orders.id', '=', 'package_orders.order_id')
            ->where('package_delivery_requests.captain_id', $captainId)
            ->where(function ($query) {
                $query->whereColumn('package_delivery_requests.captain_id', '<>', 'orders.captain_id')->orWhere('orders.captain_id', null);
            })
            ->whereColumn('packages.captain_id', '<>', 'package_delivery_requests.captain_id')
            ->today()
            ->whereNotNull('package_delivery_requests.declined_at')
            ->whereNotNull('package_orders.order_id')
            ->whereRaw('package_delivery_requests.id = (select max(pdr.id) from package_delivery_requests as pdr where pdr.package_id = package_delivery_requests.package_id AND pdr.captain_id = ' . $captainId . ' group by pdr.package_id limit 1)')
            ->groupBy('package_orders.order_id')
            ->select(DB::raw('count(*) as count'))
            ->get()
            ->count();

        $missed_orders = $total_orders - ($total_accepted + $total_declined);

        return compact('total_orders', 'total_accepted', 'total_declined', 'missed_orders');
    }
}
