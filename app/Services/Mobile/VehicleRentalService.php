<?php

namespace App\Services\Mobile;

use App\Captain;
use App\Interfaces\Mobile\VehicleRentalInterface as MobileVehicleRentalInterface;
use Illuminate\Http\Request;

final class VehicleRentalService
{
    public function __construct(protected readonly MobileVehicleRentalInterface $vehicleRentalInterface) {}

    public function getVehicleRentalStatistics(Captain $captain, Request $request): array
    {
        $vehicle_rents = $this->vehicleRentalInterface->getVehicleRentalStatistics($captain, $request);
        $rent = $captain->daily_rent ?? 0;
        $payable_rent = $captain->payableVehicleRent() ?? 0;

        return [
            'rent_count' => moneyFormat(amount: $vehicle_rents->sum('rent_count') ?? 0),
            'daily_rent' => moneyFormat(amount: $rent),
            'total_rent' => moneyFormat(amount: $vehicle_rents->sum('total_rent') ?? 0),
            'received_rents' => moneyFormat(amount: $vehicle_rents->sum('total_settled') ?? 0),
            'payable_rent' => moneyFormat($payable_rent),
        ];
    }

    public function getVehicleRentalList(Captain $captain, Request $request): array
    {
        $vehicle_rents = $this->vehicleRentalInterface->getVehicleRentalList($captain, $request);

        $rents = $vehicle_rents->map(function($rent){
            return [
                  'rented_day' => $rent->rented_day,
                    'amount' => moneyFormat($rent->amount),
                    'received_amount' => moneyFormat($rent->received_amount ?? 0),
            ];
        });

        $rent_due = moneyFormat($captain->payableVehicleRent());
        return compact('rent_due', 'rents');
    }

    public function getVehicleRentalTransactions(Captain $captain, int $perPage): array 
    {
        $transactions = $this->vehicleRentalInterface->getVehicleRentalTransactions($captain, $perPage);

        return $transactions->getCollection()->transform(function($transaction){
            return [
                    'date' => $transaction->created_at,
                    'payed_to' => $transaction->receivedBy->name,
                    'amount' => moneyFormat($transaction->amount),
                    'paid_by' => $transaction->paymentMode->name,
                    'reference_no' => $transaction->reference_no,
                    'invoice_url' => route('vehicle-rent.transaction.receipt', $transaction),
                    'attachments' => $transaction->attachments->map(function ($attachment) {
                        return [
                            'path' => asset($attachment->url),
                        ];
                    }),
                ];
        })->toArray();
    }
}
