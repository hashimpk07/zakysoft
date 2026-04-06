<?php

namespace App\Interfaces\General\ClientReports;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

interface ClientSaleInterface
{
    public function getSales(array $filters,int $perPage): LengthAwarePaginator;
}