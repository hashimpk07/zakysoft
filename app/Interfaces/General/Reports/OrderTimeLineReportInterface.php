<?php

namespace App\Interfaces\General\Reports;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;


interface OrderTimeLineReportInterface
{
    public function getOrderTimeLineReport(array $filters, int $perPage) : LengthAwarePaginator;
}