<?php

namespace App\Repositories\General;

use App\Captain;
use App\CaptainWorkingLog;
use App\Client;
use App\ClientShop;
use App\Interfaces\General\DashboardManagementInterface;
use App\OrderReport;
use App\OrderStatus;
use App\Services\General\DTO\DateRangeDTO;
use App\ThirdPartyLogisticCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardManagementInterfaceRepository implements DashboardManagementInterface
{

    public function getStatistic(DateRangeDTO $range, ?int $clientId): Collection
    {
        return OrderReport::query()
            ->select(
                'order_statuses.name',
                'order_statuses.id',
                DB::raw('COUNT(DISTINCT order_reports.id) as count'),
            )
            ->leftJoin('order_statuses', 'order_statuses.id', 'order_reports.status_id')
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->when($clientId, fn($q) => $q->where('order_reports.client_id', $clientId))
            ->whereBetween('order_reports.final_status_at', [$range->startDate, $range->endDate])
            ->groupBy('order_statuses.name', 'order_statuses.id')
            ->orderBy('order_statuses.id')
            ->belongsToMe()
            ->excludeQuadrants('shop_region.quadrant_id')
            ->finishedOrders()
            ->get();
    }

    public function getActiveBranchCount(DateRangeDTO $range, ?int $clientId): int
    {
        return OrderReport::query()
            ->select(DB::raw('COUNT(DISTINCT order_reports.shop_id) as branch_count'))
            ->when($clientId, fn($q) => $q->where('order_reports.client_id', $clientId))
            ->whereBetween('order_reports.final_status_at', [$range->startDate, $range->endDate])
            ->belongsToMe()
            ->finishedOrders()
            ->excludeQuadrants()
            ->value('branch_count') ?? 0;
    }

    public function getActiveClientsCount(DateRangeDTO $range, ?int $clientId): int
    {
        return OrderReport::query()
            ->select(DB::raw('COUNT(DISTINCT order_reports.client_id) as client_count'))
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->whereBetween('order_reports.final_status_at', [$range->startDate, $range->endDate])
            ->when($clientId, fn($q) => $q->where('order_reports.client_id', $clientId))
            ->finishedOrders()
            ->excludeQuadrants('shop_region.quadrant_id')
            ->belongsToMe()
            ->get()
            ->sum('client_count') ?? 0;
    }

    public function getActiveClients(DateRangeDTO $range): Collection
    {
        return Client::query()
            ->isActive()
            ->excludeQuadrants()
            ->with('user:id,name')
            ->whereHas('order', fn($q) => $q->withinDateRange($range->startDate, $range->endDate))
            ->get();
    }

    public function getOrderAggregates(DateRangeDTO $range, ?int $clientId): object
    {
        return OrderReport::query()
            ->when($clientId, fn($q) => $q->where('order_reports.client_id', $clientId))
            ->whereBetween('order_reports.final_status_at', [$range->startDate, $range->endDate])
            ->belongsToMe()
            ->excludeQuadrants()
            ->finishedOrders()
            ->selectRaw('
                COUNT(order_reports.id)                 as total_orders,
                COUNT(DISTINCT order_reports.shop_id)   as total_shops,
                COUNT(DISTINCT order_reports.client_id) as total_clients
            ')
            ->first();
    }

    public function getServingRegionsCount(DateRangeDTO $range, ?int $clientId): int
    {
        return OrderReport::query()
            ->selectRaw('COUNT(DISTINCT shop_region.quadrant_id) as serving_regions_count')
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->whereBetween('order_reports.final_status_at', [$range->startDate, $range->endDate])
            ->when($clientId, fn($q) => $q->where('order_reports.client_id', $clientId))
            ->finishedOrders()
            ->excludeQuadrants('shop_region.quadrant_id')
            ->belongsToMe()
            ->value('serving_regions_count') ?? 0;
    }

    public function getTotalDeliveredOrders(DateRangeDTO $range): int
    {
        return OrderReport::query()
            ->where('order_reports.status_id', OrderStatus::DELIVERED)
            ->whereBetween('order_reports.final_status_at', [$range->startDate, $range->endDate])
            ->belongsToMe()
            ->excludeQuadrants()
            ->finishedOrders()
            ->count();
    }

    public function getClientWeightsCount(DateRangeDTO $range, ?int $clientId): int
    {
        return OrderReport::query()
            ->where('order_reports.status_id', OrderStatus::DELIVERED)
            ->whereBetween('order_reports.final_status_at', [$range->startDate, $range->endDate])
            ->belongsToMe()
            ->excludeQuadrants()
            ->finishedOrders()
            ->when($clientId, fn($q) => $q->where('order_reports.client_id', $clientId))
            ->groupBy('order_reports.client_id')
            ->count();
    }

    public function getBranchOrdersCount(DateRangeDTO $range, ?int $clientId): int
    {
        return OrderReport::query()
            ->where('order_reports.status_id', OrderStatus::DELIVERED)
            ->whereBetween('order_reports.final_status_at', [$range->startDate, $range->endDate])
            ->belongsToMe()
            ->excludeQuadrants()
            ->finishedOrders()
            ->when($clientId, fn($q) => $q->where('order_reports.client_id', $clientId))
            ->count();
    }

    public function getBranchesCount(?int $clientId): int
    {
        return ClientShop::isActive()
            ->excludeQuadrants()
            ->when($clientId, fn($q) => $q->where('client_shops.client_id', $clientId))
            ->count();
    }

    // 3pl 

    public function getStatisticWithCompany(DateRangeDTO $range, ?int $clientId, ?int $company): Collection
    {
        return OrderReport::query()
            ->select(
                'order_statuses.name',
                'order_statuses.id',
                DB::raw('COUNT(DISTINCT order_reports.id) as count'),
            )
            ->leftJoin('order_statuses', 'order_statuses.id', 'order_reports.status_id')
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->when($clientId, fn($q) => $q->where('order_reports.client_id', $clientId))
            ->when($company, fn($q) => $q->whereHas(
                'captain.captainThirdParty',
                fn($q) =>
                $q->where('third_party_logistic_company_id', $company)->excludeCompanies()
            ))
            ->whereBetween('order_reports.final_status_at', [$range->startDate, $range->endDate])
            ->groupBy('order_statuses.name', 'order_statuses.id')
            ->orderBy('order_statuses.id')
            ->belongsToMe()
            ->excludeQuadrants('shop_region.quadrant_id')
            ->finishedOrders()
            ->get();
    }

    public function getTotalDeliveredOrdersWithCompany(DateRangeDTO $range, ?int $clientId, ?int $company): int
    {
        return OrderReport::query()
            ->where('order_reports.status_id', OrderStatus::DELIVERED)
            ->whereBetween('order_reports.final_status_at', [$range->startDate, $range->endDate])
            ->belongsToMe()
            ->when($clientId, fn($q) => $q->where('order_reports.client_id', $clientId))
            ->when($company, fn($q) => $q->whereHas(
                'captain.captainThirdParty',
                fn($q) =>
                $q->where('third_party_logistic_company_id', $company)->excludeCompanies()
            ))
            ->excludeQuadrants()
            ->finishedOrders()
            ->count();
    }

    public function getThirdPartyCompanies(): Collection
    {
        return ThirdPartyLogisticCompany::select('id', 'name')->active()
            ->excludeCompanies()
            ->toBase()
            ->get();
    }

    public function getCaptainsByEmploymentType(): array
    {
        return Captain::with('employmentType')
            ->selectRaw('COUNT(*) AS count, captain_employment_type_id')
            ->active()
            ->excludeQuadrants()
            ->belongsToMe()
            ->groupBy('captain_employment_type_id')
            ->get()
            ->pluck('count', 'captain_employment_type_id')
            ->toArray();
    }

    public function getActiveCaptainsCount(?int $company): int
    {
        return Captain::active()
            ->when($company, fn($q) => $q->whereHas(
                'captainThirdParty',
                fn($q) =>
                $q->where('third_party_logistic_company_id', $company)->excludeCompanies()
            ))
            ->excludeQuadrants()
            ->belongsToMe()
            ->count();
    }

    public function getCaptainWorkingHours(DateRangeDTO $range, ?int $company): float
    {
        $seconds = CaptainWorkingLog::query()
            ->whereBetween('captain_working_logs.date', [$range->startDate, $range->endDate])
            ->leftJoin('captains', 'captains.id', 'captain_working_logs.captain_id')
            ->when($company, fn($q) => $q->whereHas(
                'captain.captainThirdParty',
                fn($q) =>
                $q->where('third_party_logistic_company_id', $company)->excludeCompanies()
            ))
            ->sum('seconds_worked');

        return $seconds / 3600;
    }

    public function getTotalDeliveredWithCaptain(DateRangeDTO $range): int
    {
        return OrderReport::query()
            ->belongsToMe()
            ->whereBetween('order_reports.final_status_at', [$range->startDate, $range->endDate])
            ->whereNotNull('order_reports.captain_id')
            ->finishedOrders()
            ->excludeQuadrants()
            ->count();
    }

    public function getOrdersPerCaptain(DateRangeDTO $range, ?int $clientId, ?int $company): Collection
    {
        return OrderReport::query()
            ->where('order_reports.status_id', OrderStatus::DELIVERED)
            ->whereBetween('order_reports.final_status_at', [$range->startDate, $range->endDate])
            ->belongsToMe()
            ->excludeQuadrants()
            ->finishedOrders()
            ->when($company, fn($q) => $q->whereHas(
                'captain.captainThirdParty',
                fn($q) =>
                $q->where('third_party_logistic_company_id', $company)->excludeCompanies()
            ))
            ->when($clientId, fn($q) => $q->where('order_reports.client_id', $clientId))
            ->groupBy('order_reports.captain_id')
            ->selectRaw('captain_id, COUNT(*) as order_count')
            ->get();
    }

    public function getClientWeightsCountWithCompany(DateRangeDTO $range, ?int $clientId, ?int $company): int
    {
        return OrderReport::query()
            ->where('order_reports.status_id', OrderStatus::DELIVERED)
            ->whereBetween('order_reports.final_status_at', [$range->startDate, $range->endDate])
            ->belongsToMe()
            ->excludeQuadrants()
            ->finishedOrders()
            ->when($company, fn($q) => $q->whereHas(
                'captain.captainThirdParty',
                fn($q) =>
                $q->where('third_party_logistic_company_id', $company)->excludeCompanies()
            ))
            ->when($clientId, fn($q) => $q->where('order_reports.client_id', $clientId))
            ->count();
    }
}