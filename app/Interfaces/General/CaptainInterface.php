<?php

namespace App\Interfaces\General;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

interface CaptainInterface{
     public function getPaginated(Request $request, int $perPage = 10): LengthAwarePaginator;
     public function getStatistics(): array;
     public function getPendingRequests(array $filters, int $perPage = 10);
}