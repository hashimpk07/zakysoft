<?php

namespace App\Repositories\Mobile;

use App\Captain;
use App\Interfaces\Mobile\VehicleRentalInterface;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VehicleRentalInterfaceRepository implements VehicleRentalInterface
{
    public function getVehicleRentalStatistics(Captain $captain, Request $request): Collection
    {
        return $captain
            ->vehicleRents()
            ->select(DB::raw('sum(captain_vehicle_rents.amount) as `total_rent`'),
                     DB::raw('count(*) as `rent_count`'), 'captain_vehicle_rents.vehicle_id')
            ->addSelect([
                'total_settled' => function ($query) {
                    $query->selectRaw('sum(captain_vehicle_rent_settlements.amount)')
                    ->from('captain_vehicle_rent_settlements')
                    ->whereRaw('captain_vehicle_rent_settlements.captain_id = captain_vehicle_rents.captain_id')
                    ->whereRaw('captain_vehicle_rent_settlements.vehicle_id = captain_vehicle_rents.vehicle_id')->limit(1);
                },
            ])
            ->when($request->get('from_date'), function ($query, $from_date) {
                $query->where('captain_vehicle_rents.rented_day', '>=', now()->parse($from_date)->format('Y-m-d'));
            })
            ->when($request->get('to_date'), function ($query, $to_date) {
                $query->where('captain_vehicle_rents.rented_day', '<=', now()->parse($to_date)->format('Y-m-d'));
            })
            ->groupBy('captain_vehicle_rents.vehicle_id', 'total_settled')
            ->get();
    }

     public function getVehicleRentalList(Captain $captain, Request $request): Collection
     {
        return $captain
            ->vehicleRents()
            ->select(DB::raw('CAST(captain_vehicle_rents.rented_day as DATE) as `rented_day`'), 
                            'captain_vehicle_rents.amount',
                            DB::raw('sum(captain_vehicle_rent_settlements.amount) as received_amount'))
            ->leftJoin('captain_vehicle_rent_settlements', function ($join) {
                $join->on('captain_vehicle_rent_settlements.captain_id', 'captain_vehicle_rents.captain_id')
                ->on('captain_vehicle_rent_settlements.vehicle_id', 'captain_vehicle_rents.vehicle_id')
                ->whereRaw('CAST(captain_vehicle_rent_settlements.created_at as DATE) = CAST(captain_vehicle_rents.rented_day as DATE)');
            })
            ->when($request->get('from_date'), function ($query, $from_date) {
                $query->where('captain_vehicle_rents.rented_day', '>=', now()->parse($from_date)->format('Y-m-d'));
            })
            ->when($request->get('to_date'), function ($query, $to_date) {
                $query->where('captain_vehicle_rents.rented_day', '<=', now()->parse($to_date)->format('Y-m-d'));
            })
            ->groupBy('captain_vehicle_rents.rented_day', 'captain_vehicle_rents.amount')
            ->get();
     }
     
       public function getVehicleRentalTransactions(Captain $captain, int $perPage): ?LengthAwarePaginator
       {
            return $captain
            ->vehicleRentSettlement()
            ->with('vehicle', 'paymentMode', 'attachments', 'receivedBy')
            ->latest()
            ->paginate($perPage);
       }
}
