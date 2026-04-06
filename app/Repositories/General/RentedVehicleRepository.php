<?php

namespace App\Repositories\General;

use App\CaptainVehicleRent;
use App\CaptainVehicleRentSettlement;
use App\Interfaces\General\RentedVehicleInterface;
use App\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class RentedVehicleRepository implements RentedVehicleInterface
{
    public function getRentedVehicles(array $filters, int $perPage = 20)
    {
        return Vehicle::query()
            ->select([
                'id',
                'code',
                'type',
                'number',
                'assigned_to'
            ])

            ->with([
                'vehicleType:id,name',
                'activeCaptain:id,vehicle_id,rented_valid_at,rent',
                'captain' => function ($query) {
                    $query->select('id', 'user_id')
                        ->with([
                            'user:id,name'
                        ])
                        ->withCount('vehicleRents')
                        ->withSum('vehicleRents', 'amount')
                        ->withSum('vehicleRentSettlement', 'amount');
                }
            ])

            ->when($filters['vehicle_type'] ?? null, fn($q, $v) => $q->where('type', $v))

            ->when(
                $filters['plate_no'] ?? null,
                fn($q, $v) =>
                $q->where('number', 'like', "%$v%")
            )

            ->when($filters['captain'] ?? null, function ($q, $captain) {
                $q->whereHas('captain.user', function ($q) use ($captain) {
                    $q->where('name', 'like', "%$captain%")
                        ->orWhere('email', 'like', "%$captain%");
                });
            })

            ->rented()
            ->paginate($perPage);
    }

    public function countRentedByType(int $type): int
    {
        return Vehicle::rented()->where("type", $type)->count();
    }

    public function getTotalRents(): float
    {
        return CaptainVehicleRent::sum('amount');
    }

    public function getReceivedRents(): float
    {
        return CaptainVehicleRentSettlement::sum('amount');
    }

    public function getVehicleCaptain(Vehicle $vehicle)
    {
        return $vehicle->load([
            'vehicleType:id,name',
            'captain' => function ($query) {
                $query->select([
                    'id',
                    'code',
                    'user_id',
                    'nationality_id',
                    'captain_employment_type_id',
                    'phone_number',
                    'status',
                    'iqama_number',
                    'iqama_expiry_date',
                    'licence_number',
                    'licence_expiry_date',
                    'date_of_joining',
                    'rent_valid_from',
                    'daily_rent',
                    'current_using_app_version'
                ])
                    ->with([
                        'user:id,name,email',
                        'nationality:id,name',
                        'employmentType:id,name',
                        'regions:id,name'
                    ]);
            }
        ]);
    }

    public function getVehicleRentsWithSettlement(int $vehicleId, int $perPage = 20)
    {
        $vehicle = Vehicle::with('captain')->findOrFail($vehicleId);
        $captain = $vehicle->captain;

        return $captain
            ->vehicleRents()
            ->select(
                'captain_vehicle_rents.amount as rent_amount',
                DB::raw("MIN(captain_vehicle_rents.rented_day) as `from_date`"),
                DB::raw("MAX(captain_vehicle_rents.rented_day) as `to_date`"),
                DB::raw("SUM(captain_vehicle_rents.amount) as `total_rent`"),
                DB::raw("DATE_FORMAT(captain_vehicle_rents.rented_day,'%M %Y') as months"),
                DB::raw("DATE_FORMAT(captain_vehicle_rents.rented_day, '%m') as month"),
                DB::raw("DATE_FORMAT(captain_vehicle_rents.rented_day, '%Y') as year"),
                DB::raw("COUNT(*) as `rent_count`")
            )
            ->addSelect([
                'settled_amount' => function ($query) {
                    $query->selectRaw('SUM(captain_vehicle_rent_settlements.amount)')
                        ->from('captain_vehicle_rent_settlements')
                        ->whereColumn('captain_vehicle_rent_settlements.captain_id', 'captain_vehicle_rents.captain_id')
                        ->whereColumn('captain_vehicle_rent_settlements.vehicle_id', 'captain_vehicle_rents.vehicle_id')
                        ->whereRaw("YEAR(captain_vehicle_rent_settlements.created_at) = year AND MONTH(captain_vehicle_rent_settlements.created_at) = month")
                        ->limit(1);
                },
                'total_settled' => function ($query) {
                    $query->selectRaw('SUM(captain_vehicle_rent_settlements.amount)')
                        ->from('captain_vehicle_rent_settlements')
                        ->whereColumn('captain_vehicle_rent_settlements.captain_id', 'captain_vehicle_rents.captain_id')
                        ->whereColumn('captain_vehicle_rent_settlements.vehicle_id', 'captain_vehicle_rents.vehicle_id')
                        ->where(function ($query) {
                            $query
                                ->whereRaw("YEAR(captain_vehicle_rent_settlements.created_at) <= year AND MONTH(captain_vehicle_rent_settlements.created_at) <= month")
                                ->orWhereRaw("YEAR(captain_vehicle_rent_settlements.created_at) < year");
                        })
                        ->limit(1);
                },
                'sub_total_rent' => function ($query) {
                    $query->selectRaw('SUM(captain_vehicle_rents_1.amount)')
                        ->from('captain_vehicle_rents as captain_vehicle_rents_1')
                        ->whereColumn('captain_vehicle_rents_1.captain_id', 'captain_vehicle_rents.captain_id')
                        ->whereColumn('captain_vehicle_rents_1.vehicle_id', 'captain_vehicle_rents.vehicle_id')
                        ->where(function ($query) {
                            $query
                                ->whereRaw("YEAR(captain_vehicle_rents_1.rented_day) <= year AND MONTH(captain_vehicle_rents_1.rented_day) <= month")
                                ->orWhereRaw("YEAR(captain_vehicle_rents_1.rented_day) < year");
                        })
                        ->limit(1);
                },
            ])
            ->groupBy('months', 'month', 'year', 'captain_vehicle_rents.amount', 'captain_vehicle_rents.captain_id', 'captain_vehicle_rents.vehicle_id')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->paginate($perPage);
    }

    public function getSettlementsByVehicleAndDateRange(Vehicle $vehicle, string $from, string $to)
    {
        return $vehicle->captain
            ->vehicleRentSettlement()
            ->with(['paymentMode', 'receivedBy', 'attachments'])
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->latest()
            ->get();
    }

    public function createSettlement(Vehicle $vehicle, array $data)
    {
        $captain = $vehicle->captain;

        $settlement = $captain->vehicleRentSettlement()->create([
            'vehicle_id' => $vehicle->id,
            'amount' => $data['amount'],
            'reference_no' => $data['reference_no'],
            'payment_mode_id' => $data['payment_mode_id'],
            'received_by' => auth()->id(),
        ]);

        if (!empty($data['attachments'])) {
            $attachmentsUpload = collect($data['attachments'])->map(function (UploadedFile $attachment) {
                return [
                    'path' => str_replace(
                        'public',
                        'storage',
                        $attachment->storePublicly('public/captain_vehicle_rent_settlement_attachment')
                    )
                ];
            })->toArray();

            $settlement->attachments()->createMany($attachmentsUpload);
        }

        return $settlement;
    }
}