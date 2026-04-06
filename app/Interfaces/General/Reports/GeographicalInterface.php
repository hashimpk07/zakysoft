<?php

namespace App\Interfaces\General\Reports;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;


interface GeographicalInterface
{
    public function getGeographicalReports($zone,$fromDate,$toDate,$totalDays,$perPage);
}