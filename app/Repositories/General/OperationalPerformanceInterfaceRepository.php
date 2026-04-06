<?php

namespace App\Repositories\General;

use App\Captain;
use App\Client;
use App\ClientShop;
use App\Interfaces\General\OperationalPerformanceInterface;
use App\OrderReport;
use App\OrderStatus;
use App\Quadrant;
use App\Services\General\DTO\OperationalDateDTO;
use App\ThirdPartyLogisticCompany;
use Carbon\Carbon;

class OperationalPerformanceInterfaceRepository implements OperationalPerformanceInterface
{
    public function getTotalOrderCounts(OperationalDateDTO $dto): int
    {
        return OrderReport::query()
            ->whereBetween('final_status_at', [$dto->businessDayStart, $dto->businessDayEnd])
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->excludeQuadrants('shop_region.quadrant_id')
            ->count();
    }

    public function getTotalDeliveryCount(OperationalDateDTO $dto): int
    {
        return OrderReport::query()
            ->whereBetween('final_status_at', [$dto->businessDayStart, $dto->businessDayEnd])
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->excludeQuadrants('shop_region.quadrant_id')
            ->where('status_id', OrderStatus::DELIVERED)
            ->count();
    }

    public function getTotalFailedCount(OperationalDateDTO $dto): int
    {
        return OrderReport::query()
            ->whereBetween('final_status_at', [$dto->businessDayStart, $dto->businessDayEnd])
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->excludeQuadrants('shop_region.quadrant_id')
            ->where('status_id', '<>', OrderStatus::DELIVERED)
            ->count();
    }

    public function getTotalMonthlyTillDate(OperationalDateDTO $dto): int
    {
        return OrderReport::query()
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->excludeQuadrants('shop_region.quadrant_id')
            ->where('order_reports.status_id', OrderStatus::DELIVERED)
            ->where('final_status_at', '<=', $dto->businessDayEnd)
            ->where('final_status_at', '>=', Carbon::parse($dto->startOfMonth)->setTime(6, 0, 0))
            ->count();
    }

    public function getTotalOrdersYTD(OperationalDateDTO $dto): int
    {
        return OrderReport::query()
            ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->excludeQuadrants('shop_region.quadrant_id')
            ->where('order_reports.status_id', OrderStatus::DELIVERED)
            ->where('final_status_at', '<=', $dto->businessDayEnd)
            ->where('final_status_at', '>=', Carbon::parse($dto->startOfYear)->setTime(6, 0, 0))
            ->count();
    }

    public function getClientCount(OperationalDateDTO $dto): int
    {
        return Client::whereHas(
            'orderReports',
            fn($q) =>
            $q->whereBetween('final_status_at', [$dto->businessDayStart, $dto->businessDayEnd])
        )->count();
    }

    public function getBranchCount(OperationalDateDTO $dto): int
    {
        return ClientShop::whereHas(
            'orderReports',
            fn($q) =>
            $q->whereBetween('final_status_at', [$dto->businessDayStart, $dto->businessDayEnd])
        )->count();
    }

    public function getServingRegions(): int
    {
        return Quadrant::excludeQuadrants()->count();
    }

    public function getThirdPartyCompaniesCount(): int
    {
        return ThirdPartyLogisticCompany::count();
    }

    public function getTotalCaptains(): int
    {
        return Captain::active()->count();
    }

    public function getTotalDeliveredCaptains(OperationalDateDTO $dto): int
    {
        return Captain::whereHas(
            'orderReports',
            fn($q) =>
            $q->whereBetween('final_status_at', [$dto->businessDayStart, $dto->businessDayEnd])
        )->count();
    }
}