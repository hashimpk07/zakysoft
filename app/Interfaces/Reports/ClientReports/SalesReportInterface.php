<?php

namespace App\Interfaces\Reports\ClientReports;

use Illuminate\Pagination\LengthAwarePaginator;

interface SalesReportInterface
{
    public function getSalesReport(int $perPage = 20): LengthAwarePaginator;
}