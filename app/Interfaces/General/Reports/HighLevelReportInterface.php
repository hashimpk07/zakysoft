<?php

namespace App\Interfaces\General\Reports;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;


interface HighLevelReportInterface
{
    public function getHighLevelReport($filters,$perPage);
}