<?php

namespace App\Interfaces;

use Illuminate\Pagination\LengthAwarePaginator;

interface ClientReportInterface{
    public function getLevelReport(array $filters = [], int $perPage): LengthAwarePaginator;
}