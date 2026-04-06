<?php

namespace App\Interfaces\General\ClientReports;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

interface ClientLevelInterface
{
    public function getClients(bool $active, bool $withName);
    public function getClientShops(bool $active);
    public function getLevelReport(array $filters, int $perPage): LengthAwarePaginator;
    
}