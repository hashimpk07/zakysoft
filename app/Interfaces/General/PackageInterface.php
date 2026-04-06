<?php

namespace App\Interfaces\General;

use App\Http\Requests\General\Orders\PackageListRequest;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface PackageInterface{
    public function getPackages(PackageListRequest $request): LengthAwarePaginator;
    public function getPackageRequests(int $packageId): array;
    public function getRequestedCaptains(int $orderId, string $time): Collection;

}