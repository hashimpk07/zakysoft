<?php

namespace App\Interfaces\Reports\CaptainReports;

use Illuminate\Pagination\LengthAwarePaginator;

interface CaptainReportDeliveryInterface
{
    public function getCaptainDeliveryReport(array $filters, int $perPage = 20): LengthAwarePaginator;
     public function getStatistics(array $filters): object;
}
