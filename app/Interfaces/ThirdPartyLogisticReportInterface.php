<?php

namespace App\Interfaces;


use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

interface ThirdPartyLogisticReportInterface
{
    // order List and count
    public function getListOrderQuery(int $companyId): Builder;
    public function applyOrderFilters(Builder $query, array $filters): Builder;
    public function getOrderCounts(int $companyId, array $filters): int;

    // captain List and count
    public function baseCaptainQuery(int $companyId): Builder;
    public function applyCaptainFilters(Builder $query, array $filters): Builder;
    public function getCaptainList(int $companyId, array $filters, int $perPage);

    // vehiclee List and count
    public function baseVehicleQuery(int $companyId): Builder;
    public function applyVehicleFilters(Builder $query, array $filters): Builder;
    public function getVehicleList(int $companyId, array $filters, int $perPage);
    public function getVehicleCount(int $companyId): array;

    // captain commission List and count
    public function baseCommissionQuery(int $companyId): Builder;
    public function applyCommissionFilters(Builder $query, array $filters): Builder;
    public function getCommissionList(int $companyId, array $filters, int $perPage);
    public function getCommissionCounts(int $companyId, array $filters);

    // captain commission List and count
    public function baseCaptainCommissionCountQuery(int $captainId): Builder;
    public function baseCaptainCommissionListQuery(int $captainId): Builder;
    public function getCaptainCommissionCount(int $captainId, array $filters);
    public function getCaptainCommissionList(int $captainId, array $filters, int $perPage);
    public function getCaptainCommissionStatisticsQuery(int $companyId, array $filters,int $captainId);
    public function getTotalPayableCommissionQuery(int $companyId, array $filters,int $captainId);

    // captain transaction List
    public function getCaptainTransaction(int $companyId, array $filters, int $perPage);

    public function getCaptainsForWorkingDaysReport(int $companyId, array $filters, int $perPage);
    public function getCaptainsPerformanceReport(int $companyId, array $filters, int $perPage);

    public function getDaysFilteredCaptainsReports(array $request, int $thirdPartyCompany,int $perPage);
    public function getCaptainWorkingDaysData($from, $to, $captainIds);
    public function  captainCommissionPaymentDetails(int $companyId, array $filters, int $perPage);

    public function  getCaptainCommissionConfirmPaymentReport(int $companyId, array $filters, int $perPage);
    public function getCaptainCommissionConfirmCountSummary(int $companyId, array $filters);

    
}