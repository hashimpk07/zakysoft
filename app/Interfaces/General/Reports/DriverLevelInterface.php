<?php

namespace App\Interfaces\General\Reports;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;


interface DriverLevelInterface
{
    public function getDriverLevelReport(array $data,int $perPage);
}