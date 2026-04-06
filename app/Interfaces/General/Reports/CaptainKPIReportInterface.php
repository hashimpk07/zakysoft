<?php

namespace App\Interfaces\General\Reports;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;


interface CaptainKPIReportInterface
{
    public function getCaptains(array $filters,int $perPage): LengthAwarePaginator;
    public function getWorkingDays(array $filters, array $captainIds);

    public function getCaptainPerformanceReport(array $filters, int $perPage): LengthAwarePaginator;
}