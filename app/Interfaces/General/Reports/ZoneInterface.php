<?php

namespace App\Interfaces\General\Reports;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;


interface ZoneInterface
{
    public function getTotalOrderCount(array $filters);
    public function getZoneDetailedReports(array $filters, $totalOrderCount,$perPage);
    public function getZoneBasedReports(array $filters, $totalOrderCount);
}