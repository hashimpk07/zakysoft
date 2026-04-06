<?php

namespace App\Interfaces\General\ClientReports;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

interface ClientOrderInterface
{
    public function getClientShopOrders($query,int $perPage);    
}