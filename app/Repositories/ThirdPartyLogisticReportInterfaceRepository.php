<?php

namespace App\Repositories;

use App\Captain;
use App\CaptainCommissionPayment;
use App\Interfaces\ThirdPartyLogisticReportInterface;
use App\Order;
use App\OrderStatus;
use App\Vehicle;
use App\User;
use App\CaptainWorkingLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ThirdPartyLogisticReportInterfaceRepository implements ThirdPartyLogisticReportInterface
{
    public function getListOrderQuery(int $companyId): Builder
    {
        return Order::query()
            ->select(['orders.code', 'orders.client_order_id', 'orders.amount', 'orders.delivery_charge', 'orders.delivery_date', 'orders.created_at', 'orders.status_id', 'orders.id', 'orders.delivery_time', 'orders.client_id', 'orders.captain_id', 'orders.zone_id', 'orders.region_id', 'orders.shopname', 'orders.delivery_type', 'orders.scheduled_delivery_time_slot_id', 'orders.dispatch_at'])
            ->with(['shop:id,name,express_time,zone_id', 'shop.zone:id,name', 'shop.region:regions.id,regions.name', 'timeSlot:id,start,end', 'progress:id,name', 'captain:id,phone_number,user_id', 'captain.user:id,name', 'shop.dispatchRuleForExpress'])
            ->withClient()
            ->belongsToMe()
            ->belongsTo3pl($companyId)
            ->orderByDesc('orders.id');
    }

    public function applyOrderFilters(Builder $query, array $filters): Builder
    {
        if (!empty($filters['status_id'])) {
            $query->where('orders.status_id', $filters['status_id']);
        }

        if (!empty($filters['status'])) {
            $status = $filters['status'];
            $status = is_string($status) ? explode(',', $status) : $status;

            if (is_array($status)) {
                if (empty(array_diff($status, [OrderStatus::ORDER_PACKAGE, OrderStatus::ASSIGN_ATTEMPTS]))) {
                    $status[] = OrderStatus::NEW_ORDER;
                    $query->whereHas('shop', function ($q) {
                        $q->where('auto_assignable', 1);
                    });
                }
                $query->whereIn('orders.status_id', $status);
            } else {
                $query->where('orders.status_id', $status);
            }
        }

        if (!empty($filters['delivery_type'])) {
            $query->where('orders.delivery_type', $filters['delivery_type']);
        }

        if (!empty($filters['order_type'])) {
            $query->where('orders.delivery_type', $filters['order_type']);
        }

        if (!empty($filters['captain'])) {
            $query->where('orders.captain_id', $filters['captain']);
        }

        if (!empty($filters['client_id'])) {
            $query->where('orders.client_id', $filters['client_id']);
        }

        if (!empty($filters['clients'])) {
            $clients = is_array($filters['clients']) ? $filters['clients'] : explode(',', $filters['clients']);
            $query->whereIn('orders.client_id', $clients);
        }

        if (!empty($filters['shopname'])) {
            $shops = is_array($filters['shopname']) ? $filters['shopname'] : explode(',', $filters['shopname']);
            $query->whereIn('orders.shopname', $shops);
        }

        if (!empty($filters['zone'])) {
            $zone = $filters['zone'];
            $query->where(function ($q) use ($zone) {
                $q->where('orders.zone_id', $zone)
                    ->orWhere(function ($sub) use ($zone) {
                        $sub->whereNull('orders.zone_id')
                            ->whereHas('shop', fn($s) => $s->where('client_shops.zone_id', $zone));
                    });
            });
        }

        if (!empty($filters['region'])) {
            $region = $filters['region'];
            $query->where(function ($q) use ($region) {
                $q->where('orders.region_id', $region)
                    ->orWhere(function ($sub) use ($region) {
                        $sub->whereNull('orders.region_id')
                            ->whereHas('shop.region', fn($r) => $r->where('regions.id', $region));
                    });
            });
        }

        // Search logic combines 'search', 'orderID' and 'q' from original filter
        $search = $filters['search'] ?? $filters['orderID'] ?? $filters['q'] ?? null;
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('orders.code', 'like', "%$search%")
                    ->orWhere('orders.client_order_id', 'like', "%$search%")
                    ->orWhere('orders.id', $search);
            });
        }

        // Date logic from OrderFilter
        // Original logic: if orderID is present, skip date filter.
        // We use $search as proxy for orderID presence if it acts like one.
        // However, 'search' in new repo is generic. simpler to just apply date if present.
        // But original logic explicitly checked: if ($fromDate != null && $client_order_id == null)

        $hasOrderIdSearch = !empty($filters['orderID']);

        if (!$hasOrderIdSearch) {
            if (!empty($filters['from_date'])) {
                $fromDate = $filters['from_date'];
                $query->where(function ($q) use ($fromDate) {
                    $q->where([
                        ['orders.created_at', '>=', Carbon::parse($fromDate)->startOfDay()],
                        ['orders.delivery_type', '=', Order::DELIVERY_TYPE_FAST],
                    ])
                        ->orWhere([
                            ['orders.dispatch_at', '>=', Carbon::parse($fromDate)->startOfDay()],
                            ['orders.delivery_type', '=', Order::DELIVERY_TYPE_SCHEDULE],
                        ]);
                });
            }

            if (!empty($filters['to_date'])) {
                $toDate = $filters['to_date'];
                $query->where(function ($q) use ($toDate) {
                    $q->where([
                        ['orders.created_at', '<=', Carbon::parse($toDate)->endOfDay()],
                        ['orders.delivery_type', '=', Order::DELIVERY_TYPE_FAST],
                    ])
                        ->orWhere([
                            ['orders.dispatch_at', '<=', Carbon::parse($toDate)->endOfDay()],
                            ['orders.delivery_type', '=', Order::DELIVERY_TYPE_SCHEDULE],
                        ]);
                });
            }
        }

        if (!empty($filters['time_slot'])) {
            $timeSlot = $filters['time_slot'];
            $deliveryType = $filters['delivery_type'] ?? $filters['order_type'] ?? null;

            $query->where(function ($q) use ($timeSlot, $deliveryType) {
                $q->where('orders.scheduled_delivery_time_slot_id', $timeSlot);
                if (!$deliveryType) {
                    $q->orWhere('orders.delivery_type', Order::DELIVERY_TYPE_FAST);
                }
            });
        }

        return $query;
    }
    public function getOrderCounts(int $companyId, array $filters): int
    {
        $query = Order::belongsTo3pl($companyId);
        $query = $this->applyOrderFilters($query, $filters);
        return $query->whereIn('status_id', $filters['statuses'])->count();
    }

    public function baseCaptainQuery(int $companyId): Builder
    {
        return Captain::query()
            ->select(['captains.id', 'captains.code', 'captains.user_id', 'captains.phone_number', 'captains.nationality_id', 'captains.captain_employment_type_id', 'captains.status', 'captains.current_using_app_version', 'captains.auto_assign_priority_id'])
            ->with(['user:id,name', 'nationality:id,name', 'autoAssignPriority:id,name', 'currentShift:id,captain_id,shift_end', 'regions:id,name,quadrant_id', 'regions.quadrant:id,name', 'vehicle:id,number,type,assigned_to', 'vehicle.vehicleType:id,name'])
            ->withCount('ordersDelivered')
            ->belongsTo3pl($companyId);
    }

    public function applyCaptainFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['name'] ?? null, fn($q, $name) => $q->whereHas('user', fn($user) => $user->where('name', 'LIKE', "%$name%")))
            ->when($filters['mobile_no'] ?? null, fn($q, $mobile) => $q->where('phone_number', 'LIKE', "%$mobile%"))
            ->when($filters['vehicle_no'] ?? null, fn($q, $vehicle) => $q->whereHas('vehicle', fn($v) => $v->where('number', 'LIKE', "%$vehicle%")))
            ->when($filters['shift_status'] ?? null, function ($q, $status) {
                return $status === 'ONLINE' ? $q->has('currentShift') : $q->doesntHave('currentShift');
            })
            ->when($filters['nationality'] ?? null, fn($q, $nat) => $q->where('nationality_id', $nat))
            ->when($filters['vehicle_type'] ?? null, fn($q, $type) => $q->whereJsonContains('type_of_vehicle', $type))
            ->when($filters['captain'] ?? null, fn($q, $id) => $q->where('captains.id', $id))
            ->when($filters['status'] ?? null, fn($q, $status) => $q->where('captains.status', $status))
            ->when($filters['job_type'] ?? null, fn($q, $jobType) => $q->where('captain_employment_type_id', $jobType))
            ->when($filters['quadrant_id'] ?? null, fn($q, $qid) => $q->whereHas('regions.quadrant', fn($qu) => $qu->where('id', $qid)))
            ->when($filters['region_id'] ?? null, fn($q, $rid) => $q->whereHas('regions', fn($re) => $re->where('regions.id', $rid)));
    }

    public function getCaptainList(int $companyId, array $filters, int $perPage)
    {
        $query = $this->baseCaptainQuery($companyId);
        $query = $this->applyCaptainFilters($query, $filters);

        $query
            ->when($filters['sort_by'] ?? null, function ($q, $sort) use ($filters) {
                $order = $filters['sort_order'] ?? 'ASC';

                return match ($sort) {
                    'version' => $q->orderByRaw("CAST(current_using_app_version AS DECIMAL(10,2)) $order"),
                    'job_type' => $q->orderBy('captain_employment_type_id', $order),
                    'work_status' => $q->orderByRaw("
                        CASE captains.status
                            WHEN 'Active' THEN 1
                            WHEN 'Leave' THEN 2
                            WHEN 'Inactive' THEN 3
                            WHEN 'Banned' THEN 4
                        END $order
                    "),
                    'online_status' => $q->orderByRaw("
                        CASE
                            WHEN exists (
                                select * from shift_statuses
                                where captains.id = shift_statuses.captain_id
                                and shift_end is null
                            ) THEN 1
                            ELSE 2
                        END $order
                    "),
                    'vehicle_type' => $q->orderBy('vehicle.vehicle_type_id', $order),
                    'nationality' => $q->orderBy('nationality_id', $order),
                    default => $q,
                };
            })
            ->where('captains.status', '!=', 'Request')
            ->orderBy('captains.id', 'DESC');

        return tap($query->paginate($perPage), function ($paginator) {
            $paginator->withQueryString();
        });
    }

    public function baseVehicleQuery(int $companyId): Builder
    {
        return Vehicle::query()
            ->select(['id', 'code', 'number', 'type', 'region_id', 'current_km', 'assigned_to', 'status', 'owner_name'])
            ->with(['region:id,name', 'vehicleType:id,name', 'captain:id,user_id,captain_employment_type_id', 'captain.user:id,name', 'captain.employmentType:id,name', 'partner:id,first_name,last_name'])
            ->where(function ($q) use ($companyId) {
                $q->whereHas('thirdParty', fn($tp) => $tp->where('third_party_logistic_company_id', $companyId))->orWhereHas('captain', fn($cap) => $cap->belongsTo3pl($companyId));
            })
            ->orderByDesc('id');
    }

    public function applyVehicleFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['region'] ?? null, fn($q, $v) => $q->where('region_id', $v))
            ->when($filters['vehicle_type'] ?? null, fn($q, $v) => $q->where('type', $v))
            ->when($filters['vehicle_no'] ?? null, fn($q, $v) => $q->whereLike('number', $v))
            ->when($filters['captain'] ?? null, fn($q, $v) => $q->whereHas('captain.user', fn($u) => $u->whereLike(['name', 'email'], $v)))
            ->when($filters['status'] ?? null, function ($q, $v) {
                if ($v === 'Free') {
                    return $q->whereNull('assigned_to');
                }

                if ($v === 'Assigned') {
                    return $q->whereNotNull('assigned_to');
                }
            })
            ->when($filters['employment_type'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($cap) => $cap->where('captain_employment_type_id', $v)))
            ->when($filters['owner'] ?? null, fn($q, $v) => $q->where('owner_name', $v));
    }

    public function getVehicleList(int $companyId, array $filters, int $perPage)
    {
        $query = $this->baseVehicleQuery($companyId);
        $query = $this->applyVehicleFilters($query, $filters);

        return tap($query->paginate($perPage), function ($paginator) {
            $paginator->withQueryString();
        });
    }

    public function getVehicleCount(int $companyId): array
    {
        $baseQuery = Vehicle::query()->where(function ($query) use ($companyId) {
            $query->whereHas('thirdParty', fn($tp) => $tp->where('third_party_logistic_company_id', $companyId))->orWhereHas('captain', fn($cap) => $cap->belongsTo3pl($companyId));
        });

        return [
            'all_vehicle' => (clone $baseQuery)->count(),
            'no_of_vehicle_assigned' => (clone $baseQuery)->whereNotNull('assigned_to')->count(),
            'no_of_vehicle_free' => (clone $baseQuery)->whereNull('assigned_to')->count(),
        ];
    }

    public function baseCommissionQuery(int $companyId): Builder
    {
        $latestCommissionSub = DB::raw('(SELECT MAX(id) AS max_id, captain_id FROM captain_commissions GROUP BY captain_id) as last_commissions');

        return Captain::query()
            ->select(['captains.id', 'captains.code', 'captains.user_id', 'captains.iqama_number', 'captains.captain_employment_type_id', 'captains.nationality_id', 'captains.date_of_joining', 'captains.status'])
            ->with(['user:id,name', 'employmentType:id,name', 'nationality:id,name', 'regions:id,name,quadrant_id', 'regions.quadrant:id,name', 'lastCommission' => fn($q) => $q->select(['id', 'captain_id', 'balance'])])
            ->leftJoin($latestCommissionSub, 'captains.id', '=', 'last_commissions.captain_id')
            ->leftJoin('captain_commissions as cm', 'last_commissions.max_id', '=', 'cm.id')
            ->withCount(['orders as attended_orders' => fn($q) => $q->has('captainCommission')])
            ->withAvg(['commissions as avg_commission'], 'commission')
            ->withSum(['commissions as total_commission'], 'commission')
            ->withSum(['commissions as paid_commission'], 'settled_amount')
            ->has('orders.captainCommission')
            ->whereHas('captainThirdParty', fn($q) => $q->where('third_party_logistic_company_id', $companyId));
    }

    public function applyCommissionFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['employee_id'] ?? null, fn($q, $val) => $q->where('code', 'LIKE', $val . '%'))
            ->when($filters['captain'] ?? null, fn($q, $id) => $q->where('captains.id', $id))
            ->when($filters['name'] ?? null, fn($q, $val) => $q->whereHas('user', fn($u) => $u->where('name', 'LIKE', $val . '%')))
            ->when($filters['iqama'] ?? null, fn($q, $val) => $q->where('iqama_number', 'LIKE', $val . '%'))
            ->when($filters['region'] ?? null, fn($q, $id) => $q->whereHas('regions.quadrant', fn($cq) => $cq->where('quadrants.id', $id)))
            ->when($filters['area'] ?? null, fn($q, $id) => $q->whereHas('regions', fn($cr) => $cr->where('regions.id', $id)))
            ->when($filters['job_type'] ?? null, fn($q, $id) => $q->where('captain_employment_type_id', $id))
            ->when($filters['nationality'] ?? null, fn($q, $id) => $q->where('nationality_id', $id))
            ->when($filters['on_duty_from'] ?? null, fn($q, $date) => $q->where('date_of_joining', '>=', now()->parse($date)->format('Y-m-d')))
            ->when($filters['work_status'] ?? null, fn($q, $status) => $q->where('status', $status))
            ->when($filters['payment_status'] ?? null, function ($q, $status) {
                if ($status === 'Payable') {
                    $q->where('cm.balance', '>', 0);
                }

                if ($status === 'Tally') {
                    $q->where('cm.balance', '=', 0);
                }
            });
    }

    public function getCommissionList(int $companyId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->baseCommissionQuery($companyId);
        $query = $this->applyCommissionFilters($query, $filters);
        return tap($query->paginate($perPage), fn($p) => $p->withQueryString());
    }

    public function getCommissionCounts(int $companyId, array $filters)
    {
        $query = $this->baseCommissionQuery($companyId);
        $query = $this->applyCommissionFilters($query, $filters);
        return $query->get();
    }

    public function baseCaptainCommissionCountQuery(int $captainId): Builder
    {
        return Order::query()
            ->leftJoin('captain_commissions', 'captain_commissions.order_id', '=', 'orders.id')
            ->where('orders.captain_id', $captainId)
            ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED]);
    }

    public function baseCaptainCommissionListQuery(int $captainId): Builder
    {
        return Order::query()
            ->select(['orders.id', 'orders.delivery_date', 'orders.client_order_id', 'orders.shop_to_delivery_km', 'orders.shopname', 'orders.status_id', 'orders.client_id', 'orders.captain_id'])
            ->leftJoin('captain_commissions', 'captain_commissions.order_id', '=', 'orders.id')
            ->with(['captain:id,user_id', 'captain.user:id,name', 'client:id,user_id', 'client.user:id,name', 'shop:id,name', 'progress:id,name', 'captainCommission:id,order_id,basic_delivery_earnings,additional_km_earning,commission,settled_amount,balance,updated_at,settled_by', 'captainCommission.settledBy:id,name'])
            ->where('orders.captain_id', $captainId)
            ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED])
            ->whereHas('captainCommission')
            ->orderByDesc('captain_commissions.id');
    }

    public function applyCaptainCommissionFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['from_date'] ?? null, function ($q, $v) {
                $q->where('orders.delivery_date', '>=', Carbon::parse($v)->startOfDay());
            })
            ->when($filters['to_date'] ?? null, function ($q, $v) {
                $q->where('orders.delivery_date', '<=', Carbon::parse($v)->endOfDay());
            })
            ->when($filters['region'] ?? null, function ($q, $region) {
                $q->where(function ($sub) use ($region) {
                    $sub->where('orders.region_id', $region)->orWhereHas('shop.region', fn($r) => $r->where('id', $region));
                });
            })
            ->when($filters['q'] ?? null, function ($q, $v) {
                $q->where('orders.client_order_id', 'LIKE', "$v%");
            })
            ->when($filters['status'] ?? null, function ($q, $v) {
                $q->where('orders.status_id', $v);
            })
            ->when($filters['client'] ?? null, function ($q, $v) {
                $q->where('orders.client_id', $v);
            })
            ->when($filters['shop'] ?? null, function ($q, $v) {
                $q->where('orders.shopname', $v);
            });
    }
    public function getCaptainCommissionCount(int $captainId, array $filters)
    {
        $query = $this->baseCaptainCommissionCountQuery($captainId);
        $query = $this->applyCaptainCommissionFilters($query, $filters);

        return $query
            ->selectRaw(
                "COUNT(*) as attended_orders,
            AVG(captain_commissions.commission) as avg_commission,
            SUM(captain_commissions.commission) as total_commission,
            SUM(captain_commissions.settled_amount) as total_payed_commission
        ",
            )
            ->first();
    }
    /**
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function getCaptainCommissionList(int $captainId, array $filters, int $perPage)
    {
        $query = $this->baseCaptainCommissionListQuery($captainId);
        $query = $this->applyCaptainCommissionFilters($query, $filters);

        return tap($query->paginate($perPage), function ($paginator) {
            $paginator->withQueryString();
        });
    }
    public function getCaptainCommissionStatisticsQuery(int $companyId, array $filters, int $captainId): Builder
    {
        $query = Order::query()
            ->leftJoin('captain_commissions', 'captain_commissions.order_id', '=', 'orders.id')
            ->leftJoin('captains', 'captains.id', '=', 'orders.captain_id')

            ->leftJoin(DB::raw('(SELECT MAX(id) AS max_id, captain_id FROM captain_commissions GROUP BY captain_id) AS max_commissions'), 'captains.id', '=', 'max_commissions.captain_id')

            ->leftJoin('captain_commissions AS cm', 'max_commissions.max_id', '=', 'cm.id')

            ->has('captainCommission')
            ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED])

            ->whereHas('captain.captainThirdParty', fn($q) => $q->where('third_party_logistic_company_id', $companyId))

            ->where('orders.captain_id', $captainId);

        return $this->applyCaptainCommissionFilters($query, $filters);
    }

    public function getTotalPayableCommissionQuery(int $companyId, array $filters, int $captainId): Builder
    {
        $query = Captain::query()->has('orders.captainCommission')->belongsTo3pl($companyId)->leftJoin(DB::raw('(SELECT MAX(id) AS max_id, captain_id FROM captain_commissions GROUP BY captain_id) AS max_commissions'), 'captains.id', '=', 'max_commissions.captain_id')->leftJoin('captain_commissions AS cm', 'max_commissions.max_id', '=', 'cm.id')->selectRaw('SUM(IFNULL(cm.balance, 0)) AS total_payable_commission')->where('captains.id', $captainId);
        return $this->applyCaptainCommissionFilters($query, $filters);
    }

    public function getCaptainTransaction(int $companyId, array $filters, int $perPage)
    {
        $query = $this->baseTransactionPaymentQuery($companyId);
        $query = $this->applyTransactionPaymentFilters($query, $filters);
        return $query->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    public function baseTransactionPaymentQuery(int $companyId): Builder
    {
        return CaptainCommissionPayment::query()
            ->with(['commission', 'captain', 'settledBy', 'paymentMode'])
            ->whereHas('captain.captainThirdParty', fn($q) => $q->where('third_party_logistic_company_id', $companyId));
    }

    public function applyTransactionPaymentFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['from_date'] ?? null, function ($q, $v) {
                $q->where('settled_at', '>=', now()->parse($v)->format('Y-m-d') . ' 00:00:00');
            })
            ->when($filters['to_date'] ?? null, function ($q, $v) {
                $q->where('settled_at', '<=', now()->parse($v)->format('Y-m-d') . ' 23:59:59');
            })
            ->when($filters['captain'] ?? null, fn($q, $v) => $q->where('captain_id', $v))
            ->when($filters['paid_by'] ?? null, fn($q, $v) => $q->where('settled_by', $v))
            ->when($filters['payment_type'] ?? null, fn($q, $v) => $q->where('payment_mode_id', $v))
            ->when($filters['invoice_number'] ?? null, fn($q, $v) => $q->where('id', $v))
            ->when($filters['region'] ?? null, function ($q, $region) {
                $q->whereHas('captain.regions.quadrant', fn($sub) => $sub->where('quadrants.id', $region));
            });
    }

    public function getCaptainsForWorkingDaysReport(int $companyId, array $filters, int $perPage)
    {
        return Captain::query()
            ->select('id', 'iqama_number')
            ->with(['regions.quadrant', 'company'])
            ->withName()

            ->whereHas('company', function ($q) use ($companyId) {
                $q->where('third_party_logistic_companies.id', $companyId);
            })

            ->when($filters['areas_id'] ?? null, function ($q, $areas) {
                $q->whereHas('regions', fn($r) => $r->whereIn('region_id', $areas));
            })

            ->when($filters['job_type'] ?? null, function ($q, $type) {
                $q->where('captain_employment_type_id', $type);
            })

            ->when($filters['regions'] ?? null, function ($q, $regions) {
                $q->whereHas('regions.quadrant', fn($r) => $r->whereIn('quadrant_id', $regions));
            })

            ->when($filters['captain_id'] ?? null, fn($q, $ids) => $q->whereIn('id', $ids))

            ->when($filters['status'] ?? null, fn($q, $status) => $q->where('status', $status))
            ->orderBy('name', 'asc')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getCaptainsPerformanceReport(int $companyId, array $filters, int $perPage)
    {
        $from_date       = $filters['from_date']      ?? now()->startOfMonth()->format('Y-m-d');
        $to_date         = $filters['to_date']        ?? now()->format('Y-m-d');
        $captain         = $filters['captain']        ?? false;
        $client          = $filters['client']         ?? false;
        $region          = $filters['areas_id']       ?? [];
        $employment_type = $filters['employment_type'] ?? false;
        $status          = $filters['status']         ?? false;
        $quadrants       = $filters['regions']        ?? false;
        $q               = $filters['q']              ?? false;


        return CaptainWorkingLog::query()
            ->with(
                'captain:captains.id,code,iqama_number,captain_employment_type_id,user_id',
                'captain.user:id,name',
                'captain.employmentType',
                'captain.company:third_party_logistic_companies.name',
                'captain.regions.quadrant:id,name',
            )
            ->select('captain_id')
            ->addSelect([
                DB::raw('SUM(seconds_worked) as total_seconds_worked'),
                DB::raw('SUM(orders_received) as total_orders_received'),
                DB::raw('SUM(orders_accepted) as total_orders_accepted'),
                DB::raw('SUM(orders_delivered) as total_orders_delivered'),
                DB::raw('SUM(orders_returned) as total_orders_returned'),
                DB::raw('SUM(orders_cancelled) as total_orders_cancelled'),
                DB::raw('SUM(orders_reassigned) as total_orders_reassigned'),
                DB::raw('SUM(orders_rejected) as total_orders_rejected'),
                DB::raw('SUM(orders_try_to_accept) as orders_try_to_accept'),
                DB::raw('SUM(orders_expired) as no_of_no_response_requests'),
                DB::raw('COUNT(DISTINCT date) as working_days'),
                DB::raw('SUM(CASE WHEN orders_delivered > 0 THEN 1 ELSE 0 END) as productive_days')
            ])

            // Search
            ->when($q, function ($query, $q) {
                return $query->whereLike([
                    'captain.user.name',
                    'captain.code',
                    'captain.user.email',
                    'captain.iqama_number',
                ], $q);
            })

            ->when($captain, function ($query, $captain) {
                return $query->whereIn('captain_id', $captain);
            })

            ->when($status, function ($query, $status) {
                return $query->whereHas('captain', function ($query) use ($status) {
                    $query->where('status', $status);
                });
            })

            ->when($quadrants, function ($query, $quadrants) {
                return $query->whereHas('captain.regions.quadrant', function ($query) use ($quadrants) {
                    $query->whereIn('quadrants.id', $quadrants);
                });
            })

            ->when($region, function ($query, $region) {
                return $query->whereHas('captain.regions', function ($query) use ($region) {
                    $query->whereIn('regions.id', $region);
                });
            })

            ->when($employment_type, function ($query, $employment_type) {
                return $query->whereHas('captain', function ($query) use ($employment_type) {
                    $query->where('captain_employment_type_id', $employment_type);
                });
            })

            ->whereHas('captain', function ($query) {
                $query->whereIn('status', [
                    Captain::STATUS_ACTIVE,
                    Captain::STATUS_BANNED,
                    Captain::STATUS_INACTIVE
                ]);
            })

            ->whereBetween('date', [$from_date, $to_date])

            ->whereHas('captain.company', function ($query) use ($companyId) {
                $query->where('third_party_logistic_companies.id', $companyId);
            })

            ->groupBy('captain_id')

            ->when($filters['sort_by'] ?? null, function ($query, $by) use ($filters) {

                $order = strtolower($filters['sort_order'] ?? 'asc') === 'asc' ? 'asc' : 'desc';

                if ($by === 'acceptance_rate') {
                    $query->orderByRaw("
            CASE 
                WHEN SUM(orders_received) > 0 
                THEN ((SUM(orders_accepted) + SUM(orders_try_to_accept)) / SUM(orders_received)) * 100
                ELSE 0
            END $order
        ");
                }

                if ($by === 'success_rate') {
                    $query->orderByRaw("
            CASE 
                WHEN total_orders_accepted > 0 
                THEN (total_orders_delivered / total_orders_accepted) * 100
                ELSE 0
            END $order
        ");
                }
            })

            ->orderBy('captain_id')
            ->paginate($perPage)
            ->withQueryString();
    }
    public function getDaysFilteredCaptainsReports(array $filters, int $thirdPartyCompany, int $perPage)
    {
        $areasId   = $filters['areas_id'] ?? false;
        $jobType   = $filters['job_type'] ?? false;
        $regions   = $filters['regions'] ?? false;
        $captainId = $filters['captain_id'] ?? false;
        $status    = $filters['status'] ?? false;

        return Captain::query()
            ->select('id', 'iqama_number', 'user_id', 'firstname')
            ->with(['user', 'regions.quadrant', 'company'])
            ->when($areasId, function ($query, $areas) {
                $query->whereHas('regions', function ($q) use ($areas) {
                    $q->whereIn('region_id', $areas);
                });
            })
            ->when($jobType, function ($query, $jobType) {
                $query->where('captain_employment_type_id', $jobType);
            })
            ->when($regions, function ($query, $regions) {
                $query->whereHas('regions.quadrant', function ($q) use ($regions) {
                    $q->whereIn('quadrant_id', $regions);
                });
            })
            ->when($captainId, function ($query, $ids) {
                $query->whereIn('id', $ids);
            })
            ->when($status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->whereHas('company', function ($query) use ($thirdPartyCompany) {
                $query->where('third_party_logistic_companies.id', $thirdPartyCompany);
            })
            ->paginate($perPage)
            ->withQueryString();
    }
    public function getCaptainWorkingDaysData($from, $to, $captainIds)
    {
        return DB::table('captain_working_logs')
            ->select('captain_id', DB::raw('DATE(date) as date'))
            ->addSelect(DB::raw('SUM(seconds_worked) as working_seconds'))
            ->addSelect(DB::raw('SUM(orders_delivered) as completed_orders'))
            ->whereIn('captain_id', $captainIds)
            ->whereBetween(DB::raw('DATE(date)'), [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->groupBy('captain_id', DB::raw('DATE(date)'))
            ->get();
    }

    public function captainCommissionPaymentDetails(int $companyId, array $filters, int $perPage)
    {
        $fromDate   = $filters['from_date'] ?? false;
        $toDate     = $filters['to_date'] ?? false;
        $captain    = $filters['captain'] ?? false;
        $paidBy     = $filters['paid_by'] ?? false;
        $region     = $filters['region'] ?? false;
        $paymentType = $filters['payment_type'] ?? false;
        $invoiceNumber = $filters['invoice_number'] ?? false;

        return  CaptainCommissionPayment::query()
            ->with('commission', 'captain', 'settledBy', 'paymentMode')
            ->whereHas('captain.captainThirdParty', function ($query) use ($companyId) {
                $query->where('third_party_logistic_company_id', $companyId);
            })
            ->when($fromDate, function ($query, $from_date) {
                $query->where('settled_at', '>=', now()->parse($from_date)->format('Y-m-d') . ' 00:00:00');
            })
            ->when($toDate, function ($query, $to_date) {
                $query->where('settled_at', '<=', now()->parse($to_date)->format('Y-m-d') . ' 23:59:59');
            })
            ->when($captain, function ($query, $captain) {
                $query->where('captain_id', '=', $captain);
            })
            ->when($paidBy, function ($query, $paid_by) {
                $query->where('settled_by', '=', $paid_by);
            })
            ->when($region, function ($query, $region) {
                $query->whereHas('captain.regions.quadrant', function ($query) use ($region) {
                    $query->where('quadrants.id', $region);
                });
            })
            ->when($paymentType, function ($query, $payment_type) {
                $query->where('payment_mode_id', '=', $payment_type);
            })
            ->when($invoiceNumber, function ($query, $invoice_number) {
                $query->where('id', '=', $invoice_number);
            })
            ->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }


    public function getCaptainCommissionConfirmPaymentReport(int $companyId, array $filters = [], int $perPage)
    {
        $fromDate = $filters['from_date'] ?? null;
        $toDate   = $filters['to_date'] ?? null;
        $removedZeroCaptain = ($filters['removed_zero_captain'] ?? 1) == 1;

        $businessStart = null;
        $businessEnd   = null;

        if ($fromDate && $toDate) {
            $businessStart = Carbon::parse($fromDate)->setTime(6, 0, 0);
            $businessEnd   = Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);
        }

        $query = Captain::query()
            ->select('captains.*')
            ->leftJoin('users', 'users.id', '=', 'captains.user_id')

            // Current Balance
            ->addSelect([
                'current_balance' => DB::table('captain_commissions as cc_latest')
                    ->select('cc_latest.balance')
                    ->whereColumn('cc_latest.captain_id', 'captains.id')
                    ->orderByDesc('cc_latest.id')
                    ->limit(1),
            ])

            // Attended Orders
            ->addSelect([
                'attended_orders' => DB::table('orders')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('orders.captain_id', 'captains.id')
                    ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED])
                    ->when($businessStart && $businessEnd, function ($q) use ($businessStart, $businessEnd) {
                        $q->whereBetween('orders.delivery_date', [$businessStart, $businessEnd]);
                    })
                    ->whereExists(function ($q) {
                        $q->selectRaw(1)
                        ->from('captain_commissions as cc2')
                        ->whereColumn('cc2.order_id', 'orders.id');
                    }),
            ])

            // Average Commission
            ->addSelect([
                'avg_commission' => DB::table('captain_commissions as cc_avg')
                    ->selectRaw('AVG(cc_avg.commission)')
                    ->whereColumn('cc_avg.captain_id', 'captains.id')
                    ->when($businessStart && $businessEnd, function ($q) use ($businessStart, $businessEnd) {
                        $q->whereBetween('cc_avg.created_at', [$businessStart, $businessEnd]);
                    }),
            ])

            // Total Commission
            ->addSelect([
                'total_commission' => DB::table('captain_commissions as cc_sum')
                    ->join('orders as o_sum','o_sum.id','=','cc_sum.order_id')
                    ->selectRaw('SUM(cc_sum.commission)')
                    ->whereColumn('cc_sum.captain_id', 'captains.id')
                    ->when($businessStart && $businessEnd, function ($q) use ($businessStart, $businessEnd) {
                        $q->whereBetween('o_sum.delivery_date', [$businessStart, $businessEnd]);
                    }),
            ])

            // Additional KM Earning
            ->addSelect([
                'total_additional_km_earning' => DB::table('captain_commissions as cc_km')
                    ->selectRaw('SUM(cc_km.additional_km_earning)')
                    ->whereColumn('cc_km.captain_id', 'captains.id')
                    ->when($businessStart && $businessEnd, function ($q) use ($businessStart, $businessEnd) {
                        $q->whereBetween('cc_km.created_at', [$businessStart, $businessEnd]);
                    }),
            ]);

            // 3PL Constraint
            $query->whereHas('captainThirdParty', function ($q) use ($companyId) {
                $q->where('third_party_logistic_company_id', $companyId);
            });

            // Filters
            if (!empty($filters['captain'])) {
                $query->where('captains.id', $filters['captain']);
            }

            if (!empty($filters['vehicle_type'])) {
                $query->whereHas('vehicle.vehicleType', function ($q) use ($filters) {
                    $q->where('id', $filters['vehicle_type']);
                });
            }

            if (!empty($filters['job_type'])) {
                $query->where('captains.captain_employment_type_id', $filters['job_type']);
            }

            if (!empty($filters['status'])) {
                $query->where('captains.status', $filters['status']);
            } else {
                $query->where('captains.status', 'active');
            }

            if (!empty($filters['payment_status'])) {
                if ($filters['payment_status'] === 'Payable') {
                    $query->whereExists(function ($q) {
                        $q->from('captain_commissions as cc_filter')
                        ->whereColumn('cc_filter.captain_id', 'captains.id')
                        ->where('cc_filter.balance', '>', 0);
                    });
                } elseif ($filters['payment_status'] === 'Tally') {
                    $query->whereExists(function ($q) {
                        $q->from('captain_commissions as cc_filter')
                        ->whereColumn('cc_filter.captain_id', 'captains.id')
                        ->where('cc_filter.balance', '=', 0);
                    });
                }
            }

            if ($removedZeroCaptain) {
                $query->whereExists(function ($q) {
                    $q->selectRaw(1)
                    ->from('captain_commissions as cc_latest2')
                    ->whereColumn('cc_latest2.captain_id', 'captains.id')
                    ->where('cc_latest2.id', function ($sub) {
                        $sub->selectRaw('MAX(id)')
                            ->from('captain_commissions')
                            ->whereColumn('captain_id', 'captains.id');
                    })
                    ->where('cc_latest2.balance', '>', 0);
                });
            }

        return $query->orderByDesc('captains.id')->paginate($perPage);
    }

    public function getCaptainCommissionConfirmCountSummary(int $companyId, array $filters = [])
    {
        $fromDate = $filters['from_date'] ?? null;
        $toDate   = $filters['to_date'] ?? null;
        $removedZeroCaptain = ($filters['removed_zero_captain'] ?? 1) == 1;

        $businessStart = null;
        $businessEnd   = null;

        if ($fromDate && $toDate) {
            $businessStart = Carbon::parse($fromDate)->setTime(6, 0, 0);
            $businessEnd   = Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);
        }

        $statistics = Order::query()
            ->selectRaw('
                COUNT(*) as attended_orders,
                AVG(captain_commissions.commission) as total_avg_commission,
                SUM(captain_commissions.commission) as total_commission
            ')
            ->leftJoin('captain_commissions', 'captain_commissions.order_id', '=', 'orders.id')
            ->whereHas('captain.captainThirdParty', function ($q) use ($companyId) {
                $q->where('third_party_logistic_company_id', $companyId);
            })
            ->whereHas('captainCommission')
            ->whereIn('orders.status_id', [10,16])

            ->when($businessStart && $businessEnd, function ($q) use ($businessStart, $businessEnd) {
                $q->whereBetween('orders.delivery_date', [$businessStart, $businessEnd]);
            })

            ->when(isset($filters['captain']), fn($q) =>
                $q->where('orders.captain_id', $filters['captain'])
            )

            ->when($removedZeroCaptain, fn($q) =>
                $q->where('captain_commissions.balance', '>', 0)
            )
            ->first();

            $balance = Captain::query()
                ->leftJoin(
                    DB::raw('(SELECT MAX(id) as max_id, captain_id FROM captain_commissions GROUP BY captain_id) as latest'),
                    'captains.id',
                    '=',
                    'latest.captain_id'
                )
                ->leftJoin('captain_commissions', 'latest.max_id', '=', 'captain_commissions.id')
                ->selectRaw('
                    COUNT(DISTINCT captains.id) as captains_count,
                    SUM(IFNULL(captain_commissions.balance,0)) as total_payable_commission
                ')
                ->whereHas('captainThirdParty', function ($q) use ($companyId) {
                    $q->where('third_party_logistic_company_id', $companyId);
                })
                ->when(isset($filters['captain']), fn($q) =>
                    $q->where('captains.id', $filters['captain'])
                )
                ->when($removedZeroCaptain, fn($q) =>
                    $q->where('captain_commissions.balance', '>', 0)
                )
                ->first();
        return [
            'attended_orders'          => (int) ($statistics->attended_orders ?? 0),
            'total_avg_commission'     => number_format($statistics->total_avg_commission ?? 0, 2),
            'total_commission'         => number_format($statistics->total_commission ?? 0, 2),
            'captains_count'           => (int) ($balance->captains_count ?? 0),
            'total_payable_commission' => number_format($balance->total_payable_commission ?? 0, 2),
        ];
    }

}
