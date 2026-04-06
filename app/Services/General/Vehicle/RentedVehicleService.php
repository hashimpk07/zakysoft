<?php

namespace App\Services\General\Vehicle;

use App\CaptainVehicleRentSettlement;
use App\Http\Resources\General\Vehicle\RentedVehicleResource;
use App\Http\Resources\General\Vehicle\VehicleCaptainResource;
use App\Interfaces\General\RentedVehicleInterface;
use App\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class RentedVehicleService
{
    public function __construct(protected readonly RentedVehicleInterface $repository)
    {
    }

    public function getRentedVehicles(Request $request): array
    {
        $filters = $request->only(['vehicle_type', 'plate_no', 'captain']);
        $perPage = $request->get('per_page', 20);

        $data = RentedVehicleResource::collection($this->repository->getRentedVehicles(filters: $filters, perPage: $perPage))
            ->response()
            ->getData(true);

        return [
            'vehicles' => $data['data'] ?? [],
            'pagination' => $data['meta'] ?? null,
        ];
    }

    public function getStatistics(): array
    {
        $totalRents = $this->repository->getTotalRents();
        $receivedRents = $this->repository->getReceivedRents();

        return [
            'statistics' => [
                'rented_cars' => $this->repository->countRentedByType(1),
                'rented_vans' => $this->repository->countRentedByType(3),
                'rented_bikes' => $this->repository->countRentedByType(4),
                'total_rents' => $totalRents,
                'received_rents' => $receivedRents,
                'rent_due' => $totalRents - $receivedRents,
            ],
        ];
    }

    public function getVehicleCaptain(Vehicle $vehicle): array
    {
        $vehicle = $this->repository->getVehicleCaptain($vehicle);
        $payable_rent = $vehicle?->captain?->payableVehicleRent() ?? 0;

        return [
            'captain' => new VehicleCaptainResource($vehicle),
            'payable_rent' => $payable_rent,
        ];
    }

    public function getVehicleRentsWithSettlement(int $vehicleId, int $perPage = 20): array
    {
        $data = VehicleRentResource::collection($this->repository->getVehicleRentsWithSettlement(vehicleId: $vehicleId, perPage: $perPage))->response()->getData(true);

        return [
            'rents' => $data['data'],
            'pagination' => $data['meta'],
        ];
    }

    public function getSettlementHistory(Vehicle $vehicle, string $from, string $to): Collection
    {
        $settlements = $this->repository->getSettlementsByVehicleAndDateRange($vehicle, $from, $to);

        return $settlements->map(function ($settlement) {
            return [
                'id' => $settlement->id,
                'payment_date' => $settlement->created_at->format('d/m/Y'),
                'payment_time' => $settlement->created_at->format('h:i A'),
                'received_by' => $settlement->receivedBy->name,
                'payment_type' => $settlement->paymentMode->name,
                'amount' => number_format($settlement->amount, 2),
                'reference' => $settlement->reference_no,
                'attachments' => $settlement->attachments->map(fn($attachment) => [
                    'path' => $attachment->path,
                    'name' => basename($attachment->path),
                ]),
            ];
        });
    }

    public function getReceipt(CaptainVehicleRentSettlement $settlement): string
    {
        $settlement->load('captain.user', 'paymentMode', 'receivedBy');
        $pdf = \PDF::loadView('prints.rental.receipt', compact('settlement'))
            ->setOptions([
                'margin-top' => 0,
                'margin-left' => 0,
                'margin-right' => 0,
                'margin-bottom' => 0
            ]);

        return base64_encode($pdf->output());
    }

    public function receiveRent(Vehicle $vehicle, array $data)
    {
        return $this->repository->createSettlement($vehicle, $data);
    }
}
