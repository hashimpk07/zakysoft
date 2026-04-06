<?php

namespace App\Services\General\Orders;

use App\Interfaces\General\PackageInterface;

final class PackageService{

public function __construct(protected readonly PackageInterface $packageInterface)
{
  
}
    public function getPackageRequests(int $packageId): array
{
    ['package' => $package, 'requests' => $requests] = $this->packageInterface->getPackageRequests($packageId);

    if ($requests->isEmpty()) {
        return [
            'requests' => [],
            'message'  => $package->dispatch_after->isFuture()
                ? 'Waiting for seed orders, it will be dispatched after ' . $package->dispatch_after->format('Y-m-d H:i:s')
                : 'Waiting for captains',
        ];
    }

    return [
        'requests' => $requests->map(fn($group, $time) => [
            'dispatched_at' => $time . ':00',
            'reach'         => $group->count(),
            'package_id'    => $group->first()->package_id,
        ])->values(),
        'message' => null,
    ];
}

public function getRequestedCaptains(int $orderId, string $time): array
{
    $requests = $this->packageInterface->getRequestedCaptains($orderId, $time);

    return [
        'captains' => $requests->map(fn($req) => [
            'name'      => $req->captain->firstname . ' ' . $req->captain->lastname,
            'sent_at'   => now()->parse($req->sended_at)->format('Y-m-d H:i:s'),
        ]),
    ];
}
}