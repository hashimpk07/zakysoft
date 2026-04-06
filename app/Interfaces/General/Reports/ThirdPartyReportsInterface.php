<?php

namespace App\Interfaces\General\Reports;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;


interface ThirdPartyReportsInterface
{
    public function getAllCaptainCommissionReport(array $filters, int $perPage);
    public function getAllCaptainCommissionCountSummary(array $filters);
    public function getAllCaptainCommissionTotalBalance(array $filters);

    public function getSpecificCaptainCommissionStatistics(int $captainId, array $filters);
    public function getSpecificCaptainCommissionOrders(int $captainId, array $filters, int $perPage);

    public function getThirdPartyCommissionReport(array $filters, int $perPage);
    public function getThirdPartyCommissionCount(array $filters);
    public function getThirdPartyCommissionBalance(array $filters);
    public function getSpecificThirdPartyCompanyCount(int $companyId, array $filters); 
    public function getSpecificThirdPartyCompanyOrders(int $companyId, array $filters,int $perPage);

    public function getLatestThirdPartyCompanyCommission($companyId);
    public function updateThirdPartyCompanyCommission($commission);
    public function createThirdPartyCompanyCommissionPayment(array $data);
    public function createThirdPartyCompanyCommissionAttachments($commission, array $attachments);

}