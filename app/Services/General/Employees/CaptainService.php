<?php


namespace App\Services\General\Employees;

use App\Http\Resources\General\Employees\CaptainListResource;
use App\Http\Resources\General\Employees\CaptainRequestResource;
use App\Interfaces\General\CaptainInterface;
use Illuminate\Http\Request;

final class CaptainService
{
    public function __construct(protected readonly CaptainInterface $captainInterface)
    {
    }

    public function listCaptains(Request $request): array
    {
        $captains = $this->captainInterface->getPaginated($request, $request->get('per_page', 10));
        $data = CaptainListResource::collection($captains)->response()->getData(true);
        return [
            'captains' => $data['data'],
            'pagination' => $data['meta']
        ];
    }

    public function getStatistics(): array
    {
        return $this->captainInterface->getStatistics();
    }

    public function getRequestedCaptains(Request $request): array
    {
        $captains =  $this->captainInterface->getPendingRequests($request->all(), $request->get('per_page', 10));
        $data = CaptainRequestResource::collection($captains)->response()->getData(true);
         return [
            'captains' => $data['data'],
            'pagination' => $data['meta']
        ];
    }
}