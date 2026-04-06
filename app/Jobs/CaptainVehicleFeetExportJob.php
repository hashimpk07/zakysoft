<?php

namespace App\Jobs;

use App\Captain;
use App\Exports\QueueExport;
use Illuminate\Support\Facades\Log;

class CaptainVehicleFeetExportJob extends QueueExport
{
    protected int $chunk = 1000;
    protected string $file_name = 'captain_fleet_report';

    public $timeout = 100000;

    public function data(): array
    {
        $data = [];
        $captains = $this->getReport();
        foreach ($captains as $captain) {
            $data[] = [
                'captain' => $captain->user->name ?? 'N/A',
                'region' => $captain->regions->pluck('name')->implode(', ') ?? 'N/A',
                'vehicle_type' => $captain->vehicle->vehicleType->name ?? 'N/A',
                'vehicle_no' => $captain->vehicle->vehicle_number ?? 'N/A',
                'current_km' => $captain->vehicle->current_km ?? 0,
                'receivable_amount' => $captain->accepted_vehicle_fleets_sum_amount ?? 0,
                'pending_amount' => $captain->pending_vehicle_fleets_sum_amount ?? 0,
            ];
        }

        Log::info('CaptainFleetExportJob chunk processed', [
            'page_done' => $this->export->page_done,
            'rows' => count($data),
        ]);

        return $data;
    }

    protected function getReport()
    {
        $filters = $this->export->filters ?? [];
        $offset = ($this->export->page_done ?? 0) * $this->chunk;

        return Captain::query()
            ->with([
                'user',
                'regions',
                'vehicle.vehicleType'
            ])
            ->withSum('pendingVehicleFleets', 'amount')
            ->withSum('acceptedVehicleFleets', 'amount')
            ->active()

            ->when($filters['captains'] ?? null, function ($q, $search) {
                $q->whereHas('user', function ($query) use ($search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('first_name', 'LIKE', "%{$search}%")
                        ->orWhere('last_name', 'LIKE', "%{$search}%")
                        ->orWhereRaw("CONCAT(first_name,' ',last_name) LIKE ?", ["%{$search}%"]);
                    });
                });
            })

            ->when($filters['region'] ?? null, function ($q, $region) {
                $q->whereHas('regions', function ($query) use ($region) {
                    $query->where('regions.id', $region);
                });
            })

            ->when($filters['vehicle_type'] ?? null, function ($q, $type) {
                $q->whereHas('vehicle.vehicleType', function ($query) use ($type) {
                    $query->where('vehicle_types.id', $type);
                });
            })

            ->limit($this->chunk)
            ->offset($offset)
            ->get();
    }

    public function count(): int
    {
        return Captain::query()
            ->active()
            ->count();
    }

    public function headers(): array
    {
        return [
            'Captain',
            'Region',
            'Vehicle Type',
            'Vehicle No',
            'Current KM',
            'Receivable Amount',
            'Pending Amount'
        ];
    }
}