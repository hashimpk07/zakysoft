<?php

namespace App\Interfaces\General\Reports;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;


interface VendorLevelInterface
{
    public function getVendorReports(Request $request, int $perPage);
}