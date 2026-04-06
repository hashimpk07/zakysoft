<?php
namespace App\Cache;

use App\AutoAssignPriority;
use App\Captain;
use App\OrderStatus;
use App\User;
use Carbon\Carbon;

class StreamlineCaptain extends Cache
{

    protected $data = [];

    public function __construct($data = [])
    {
        $this->data = $data;
        parent::__construct();
    }

    public function withUserPermission()
    {
        $user = auth()->user();

        if (!$user || $user->data_permission == \App\User::DATA_PERMISSION_ALL_ACCESS_BASED) {
            return $this;
        }

        $sets = [];
        $permission = $user->dataPermission();

        $regions = collect([]);
        if ($user->data_permission == \App\User::DATA_PERMISSION_CLIENT_BASED) {
            $allowedZones = \App\ClientShop::whereIn('client_id', $permission->pluck('id'))->pluck('zone_id')->unique();
            $regions = \App\Zone::whereIn('id', $allowedZones)->pluck('region_id')->unique();
        } elseif ($user->data_permission == \App\User::DATA_PERMISSION_BRANCH_BASED) {
            $regions = \App\Zone::whereIn('id', $permission->pluck('zone_id'))->pluck('region_id')->unique();
        } elseif ($user->data_permission == \App\User::DATA_PERMISSION_ZONE_BASED) {
            $regions = \App\Zone::whereIn('id', $permission->pluck('id'))->pluck('region_id')->unique();
        } elseif ($user->data_permission == \App\User::DATA_PERMISSION_REGION_BASED) {
            $regions = \App\Region::whereIn('quadrant_id', $permission->pluck('id'))->pluck('id');
        }

        if ($regions->isNotEmpty()) {
            foreach ($regions as $v) {
                $sets[] = "{$this->key()}:by:regions:$v";
            }
            $this->setSets([$sets]);
        }

        return $this;
    }

    public function set($id, $data, $score = null)
    {
        parent::set($id, $data, $score);

        // Custom indexing for regions (array field)
        if (isset($data['regions']) && is_array($data['regions'])) {
            $score = $score ?? $data['created_at'] ?? $id;
            foreach ($data['regions'] as $regionId) {
                \Illuminate\Support\Facades\Redis::zadd("{$this->key()}:by:regions:$regionId", $score, $this->id($id));
            }
        }
    }

    public function delete($id)
    {
        $data = $this->get($this->id($id))->toArray();
        parent::delete($id);

        if (isset($data['regions']) && is_array($data['regions'])) {
            foreach ($data['regions'] as $regionId) {
                \Illuminate\Support\Facades\Redis::zrem("{$this->key()}:by:regions:$regionId", $this->id($id));
            }
        }
    }

    public static function fromCaptain(Captain $captain)
    {
        $instance = new static();

        $icon = $captain->vehicle_type_id == 4 ? 'bike' : 'van';
        $captain->append('online_state'); // Ensure accessor is appended

        if (in_array('Busy', $captain->online_state)) {
            $icon = $icon . '-busy';
        }

        if (in_array('Offline', $captain->online_state)) {
            $icon = $icon . '-offline';
        }

        // if (in_array('Idle', $captain->online_state) && !in_array('Busy', $captain->online_state)) {
        //     $icon = $icon . '-idle';
        // }

        $current_orders = $captain->currentOrder
            ->filter(function ($order) {
                return !in_array($order->status_id, [
                    OrderStatus::DELIVERED,
                    OrderStatus::CANCEL_REQUEST_ACCEPTED,
                    OrderStatus::CANCEL,
                    OrderStatus::CLIENT_RETURN_ACCEPTED,
                    OrderStatus::RETURN_TO_FORYOU,
                    OrderStatus::FORYOU_RETURN_ACCEPTED,
                    OrderStatus::RETURN_TO_CLIENT,
                    OrderStatus::CLIENT_RETURN_DECLINE,
                ]);
            })
            ->map(function ($order) use ($captain) {
                $eta = $order->eta();

                return [
                    'id' => $order->id,
                    "client_order_id" => $order->client_order_id,
                    "client" => $order->client->user->name ?? "N/A",
                    "shop" => $order->shop->name ?? "N/A",
                    'location' => $order->location,
                    'status' => $order->progress->name ?? "N/A",
                    'status_id' => $order->status_id,
                    // simplified ETA for cache
                    "eta" => ($eta != -1) ? secondsToTime($eta) : (in_array($order->status_id, [OrderStatus::SHIPPED, OrderStatus::REACHED_DESTINATION]) ? null : 'N/A'),
                ];
            })->values()->toArray();

        // Delivered orders count logic (Business Day)
        $now = now();
        if ($now->format('H:i:s') < '06:00:00') {
            $businessDayStart = $now->copy()->subDay()->setTime(6, 0, 0);
            $businessDayEnd = $now->copy()->setTime(5, 59, 59);
        } else {
            $businessDayStart = $now->copy()->setTime(6, 0, 0);
            $businessDayEnd = $now->copy()->addDay()->setTime(5, 59, 59);
        }

        $delivered_orders_count = $captain->delivered_orders_count ?? $captain->deliveredOrders()
            ->whereBetween('delivery_date', [$businessDayStart, $businessDayEnd])
            ->count();


        $onlineStateStatus = 'offline';
        if (in_array('Busy', $captain->online_state))
            $onlineStateStatus = 'busy';
        elseif (in_array('Free', $captain->online_state))
            $onlineStateStatus = 'free';
        elseif (in_array('Idle', $captain->online_state))
            $onlineStateStatus = 'idle';

        $instance->data = [
            "id" => $captain->id,
            "name" => $captain->user->name ?? $captain->name ?? '',
            "code" => $captain->code,
            "phone_number" => $captain->phone_number,
            "email" => $captain->user->email ?? '',
            "iqama_number" => $captain->iqama_number,
            "lat" => $captain->lat,
            "lng" => $captain->lng,
            "captain_employment_type_id" => $captain->captain_employment_type_id,
            "vehicle_type_id" => $captain->vehicle_type_id,
            "status_id" => $captain->status_id,
            "status" => $captain->status,

            "priority" => AutoAssignPriority::getPriorityText($captain->auto_assign_priority_id),
            "online_state" => $captain->online_state,
            "online_state_status" => $onlineStateStatus,
            "icon" => $icon,
            "profile_pic_path" => $captain->profile_pic_path,

            "regions" => $captain->regions->pluck('id')->toArray(),
            "region_names" => $captain->regions->pluck('name')->toArray(),
            "company_id" => $captain->third_party_logistic_company_id ?? null,
            "third_party_company" => $captain->company->name ?? "",
            "employment_type" => $captain->employmentType ? $captain->employmentType->name : 'N/A',

            "lat" => $captain->location->latitude ?? null,
            "lng" => $captain->location->longitude ?? null,
            "last_location_update" => $captain->location->updated_at ?? null,
            "location" => $captain->location, // Full location object

            "current_orders" => $current_orders,
            "current_order_count" => count($current_orders),
            "delivered_orders_count" => $delivered_orders_count,

            "current_shift_started_at" => $captain->currentShift ? $captain->currentShift->shift_start : null,
            "total_seconds_worked" => $captain->activeTodaySeconds(),
        ];
        return $instance;
    }

    public function filterable()
    {
        return [
            "id",
            "captain_employment_type_id",
            "vehicle_type_id",
            "company_id",
            "status",
            "status_id",
            "online_state_status"
        ];
    }

    public function searchables()
    {
        return [
            "name" => "TEXT",
            "code" => "TEXT",
            "phone_number" => "TEXT",
            "email" => "TEXT",
            "iqama_number" => "TEXT"
        ];
    }
}
