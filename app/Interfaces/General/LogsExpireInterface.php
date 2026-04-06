<?php

namespace App\Interfaces\General;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

interface LogsExpireInterface
{
    public function getLogsList(int $perPage);
    public function getExpireList(array $filters, int $perPage);
   
}
