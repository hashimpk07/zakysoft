<?php

namespace App\Interfaces\General\ClientReports;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

interface BranchPerformanceInterface
{
    public function baseQuery(array $filters): Builder;
    public function getTotals(Builder $query);
    public function getReports(Builder $query, int $perPage);
    
}