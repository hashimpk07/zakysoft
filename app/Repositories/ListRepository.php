<?php

namespace App\Repositories;

use App\asset;
use App\AssetCategory;
use App\AutoAssignPriority;
use App\CancellationReason;
use App\Captain;
use App\CaptainEmploymentType;
use App\Client;
use App\ClientShop;
use App\ClientSource;
use App\CommissionRule;
use App\Country;
use App\Designation;
use App\DesignationType;
use App\DispatchRule;
use App\GeneralExport;
use App\Interfaces\ListInterface;
use App\LeadStatus;
use App\OrderStatus;
use App\Quadrant;
use App\Region;
use App\Role;
use App\ShiftRule;
use App\ThirdPartyLogisticCompany;
use App\TimeSlot;
use App\User;
use App\Vehicle;
use App\vehicle_type;
use App\Zone;
use App\Partner;
use App\Order;
use App\PaymentMode;
use App\Bank;
use App\MainZone;
use App\PointOfContactPersonDesignation;
use App\Tire;
use App\City;
use App\ClientShopTimeSlot;
use App\DeliveryType;
use App\OrderPendingReason;
use App\PriorityTag;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ListRepository implements ListInterface
{
    public function getCaptains(array $filters = [])
    {
        return Captain::whereNotIn('status', [Captain::STATUS_REQUEST])
            ->select('firstname', 'id')
            ->when(($filters['online'] ?? false) === true, fn($q) => $q->online())
            ->when(($filters['offline'] ?? false) === true, fn($q) => $q->offline())
            ->when(($filters['active'] ?? false) === true, fn($q) => $q->active())
            ->when(isset($filters['3pl_company']), fn($q) => $q->belongsTo3pl($filters['3pl_company']))
            ->when(($filters['belongs_me'] ?? false) === true, fn($q) => $q->belongsToMe())
            ->orderBy('firstname')
            ->toBase()
            ->get();
    }

    public function getCountries($hasCaptain = true)
    {
        $countries = Country::query();

        if ($hasCaptain) {
            $countries->has('captains');
        }

        return $countries->select('name', 'id')->orderBy('name')->toBase()->get();
    }

    public function getShiftRules()
    {
        return ShiftRule::select('id', 'name')->active()->toBase()->get();
    }

    public function getQuadrant(bool $belongsToMe = false)
    {
        return Quadrant::select('name', 'id')->when($belongsToMe, fn($q) => $q->belongsToMe())->orderBy('name')->toBase()->get();
    }

    public function getCaptainEmploymentType()
    {
        return CaptainEmploymentType::select('name', 'id')->toBase()->orderBy('name')->get();
    }

    public function getCommissionRule()
    {
        return CommissionRule::select('id', 'name')->toBase()->orderBy('name')->get();
    }

    public function getThirdPartyCompanies(bool $active = true)
    {
        $third_party_companies = ThirdPartyLogisticCompany::query();
        if ($active) {
            $third_party_companies->active();
        }
        return $third_party_companies->select('id', 'name', 'name_ar')->toBase()->get();
    }

    public function getOperationEmployees()
    {
        return User::select('id', 'name')->active()->operations()->toBase()->get();
    }

    public function getRoles()
    {
        return Role::select('id', 'name', 'display_name')->toBase()->get();
    }
    public function getAssetCategory()
    {
        return AssetCategory::select('id', 'name')->toBase()->get();
    }

    public function getDispatchRules()
    {
        return DispatchRule::select('id', 'name')->toBase()->get();
    }

    public function getClientSources()
    {
        return ClientSource::toBase()->get();
    }

    public function getTimeSlots()
    {
        return TimeSlot::select('name', 'id')->toBase()->get();
    }

    public function getClients(bool $isActive = true, bool $withName = false)
    {
        $clients = Client::query();
        if ($withName) {
            $clients->withName();
        }

        if ($isActive) {
            $clients->isActive();
        }

        return $clients->join('users', 'users.id', '=', 'clients.user_id')->select('clients.id', 'users.name')->toBase()->get();
    }

    public function getClientShops(array $filters = []): Collection
    {
        return ClientShop::query()
            ->select(['id', 'name', 'client_id'])
            ->when($filters['hasDeliveryTypes'] ?? true, fn($q) => $q->has('deliveryTypes'))
            ->when($filters['clientId'] ?? null, fn($q, $clientId) => $q->where('client_id', $clientId))
            ->when($filters['active'] ?? false, fn($q) => $q->isActive())
            ->toBase()
            ->get();
    }
    public function getOrderStatus(?array $statusIds = null)
    {
        return OrderStatus::select('id', 'name', 'name_ar')->when($statusIds, fn($q) => $q->whereIn('id', $statusIds))->orderBy('priority')->toBase()->get();
    }

    public function getAreas()
    {
        return Region::join('quadrants', 'quadrants.id', '=', 'regions.quadrant_id')->select('regions.id', 'regions.name', 'quadrants.name as quadrant_name')->toBase()->get();
    }

    public function getZones()
    {
        return Zone::select('name', 'id')->toBase()->get();
    }

    public function getMainRoles()
    {
        return DesignationType::select('id', 'name')->toBase()->get();
    }

    public function getSubRoles()
    {
        return Designation::select('id', 'name')->toBase()->get();
    }

    public function getVehicles(bool $assigned = false, bool $all = false)
    {
        $vehicles = Vehicle::query();

        // If $all = true → return everything (skip filtering)
        if (!$all) {
            if ($assigned) {
                // Assigned vehicles → assigned_to is not null
                $vehicles->whereNotNull('assigned_to');
            } else {
                // Unassigned vehicles → assigned_to is null
                $vehicles->whereNull('assigned_to');
            }
        }

        return $vehicles->select('name', 'number', 'id')->toBase()->get();
    }

    public function getOrderCancellationReasons($active = true)
    {
        return CancellationReason::select('id', 'reason', 'reason_ar', 'is_caused_by_4u')->when($active, fn($q) => $q->active())->toBase()->get();
    }

    public function getVehicleTypes()
    {
        return vehicle_type::select('id', 'name')->toBase()->get();
    }

    public function getOrderReportsCaptains(int $clientId)
    {
        return Order::query()
            ->select('captains.id as captain_id', 'captain_users.name as captain_name')
            ->leftJoin('captains', 'orders.captain_id', '=', 'captains.id')
            ->leftJoin('users as captain_users', 'captains.user_id', '=', 'captain_users.id')
            ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CANCEL, OrderStatus::RETURN_TO_CLIENT, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::FORYOU_RETURN_ACCEPTED])
            ->whereNotNull('orders.captain_id')
            ->whereNotNull('captain_users.name')
            ->when($clientId, fn($q) => $q->where('orders.client_id', $clientId))
            ->distinct()
            ->orderBy('captain_users.name')
            ->toBase()
            ->get();
    }

    public function checkUserHasPendingExport(User $user, string $exportType): bool
    {
        return GeneralExport::where('export_type', $exportType)
            ->whereNull('send_at')
            ->where('created_by', $user->id)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();
    }

    public function getAutoAssignPriority()
    {
        return AutoAssignPriority::orderBy('id')->toBase()->get();
    }
    public function getOwner()
    {
        return Partner::select(
            'id',
            DB::raw("CONCAT(first_name, ' ', last_name) as name")
        )->get();
    }

    public function getAsset(?Closure $closure = null)
    {
        $query = asset::select('asset_name', 'category_id', 'id');
        if ($closure) {
            $closure($query);
        }
        return $query->toBase()->get();
    }
    public function getPaymentType()
    {
        return PaymentMode::all('id', 'name');
    }

    public function getBankList()
    {
        return Bank::orderby('name')->get();

    }

    public function getDesignation()
    {
        return PointOfContactPersonDesignation::all();
    }

    public function getMainZone()
    {
        return MainZone::query()->select('id', 'iso', 'name')->orderByDesc('id')->get();
    }

    public function getTire()
    {
        return Tire::query()->select('id', 'name')->orderByDesc('id')->get();
    }

    public function getCity()
    {
        return City::select('id', 'name')->toBase()->get();
    }

    public function getClientTimeSlots()
    {
        return ClientShopTimeSlot::get();
    }

    public function getCriticalAssignPriority()
    {
        return DeliveryType::all();
    }

    public function getDeliveryType()
    {
        return AutoAssignPriority::toBase()->get();
    }

    public function getShiftRule()
    {
        return ShiftRule::select('id', 'name')->latest()->get();
    }

    public function getOrderPendingReason()
    {
        return OrderPendingReason::select('id', 'reason')->get();
    }

    public function getPriorityTags()
    {
        return PriorityTag::toBase()->get();
    }

    public function getSalesLeadCreators()
    {
        return User::query()
            ->whereHas('salesLeadsCreated')
            ->select('id', 'name')
            ->get();
    }

    public function getLeadStatuses(){
        return LeadStatus::toBase()->get();
    }

    public function getPlatForms()
    {
        return ClientSource::select('id', 'name')->get();   
    }

}
