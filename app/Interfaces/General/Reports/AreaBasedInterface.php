<?php

namespace App\Interfaces\General\Reports;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;


interface AreaBasedInterface
{
    public function getAreaBasedReports($request, $perPage);
    public function getAreaBasedTotals($request);

}