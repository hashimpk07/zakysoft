<?php

namespace App\Jobs;

use App\Zone;
use App\Exports\QueueExport;
use Illuminate\Support\Facades\Log;

class ZoneReportExportJob extends QueueExport
{
    protected int $chunk = 1000;
    protected int $totalData = 0;
    protected string $file_name = 'zone_report';
    public $tries = 3;
    public $timeout = 900;
    public $retryAfter = 60;

    /**
     * Prepare data for Excel export.
     */
    public function data(): array
    {
        try {
            $zones = $this->getReport();

            $data = [];
            foreach ($zones as $zone) {
                $data[] = [
                    $zone['id'] ?? '',
                    $zone['country'] ?? '',
                    $zone['region'] ?? '',
                    $zone['area'] ?? '',
                    $zone['tire'] ?? '',
                    $zone['zone'] ?? '',
                    $zone['status'] ?? '',
                ];
            }

            return $data;

        } catch (\Throwable $e) {

            Log::channel('commission')->error('ZoneReportExportJob failed: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Get zone report data with filters.
     */
    private function getReport(): array
    {
        try {
            $request = $this->export->filters ?? [];

            $query = Zone::query()
                ->with(['mainZone', 'region.quadrant', 'tire'])
                ->orderBy('id', 'desc');

            // Country Filter
            if (!empty($request['country'])) {
                $query->whereHas('mainZone', function ($q) use ($request) {
                    $q->where('id', $request['country']);
                });
            }

            // Region Filter
            if (!empty($request['region'])) {
                $query->whereHas('region.quadrant', function ($q) use ($request) {
                    $q->where('id', $request['region']);
                });
            }

            // Area Filter
            if (!empty($request['area'])) {
                $query->where('region_id', $request['area']);
            }

            $zones = $query
                ->limit($this->chunk)
                ->offset(($this->chunk * ($this->export->page_done ?? 0)))
                ->get()
                ->map(function ($zone) {
                    return [
                        'id'      => $zone->id,
                        'country' => optional($zone->mainZone)->name . " (" . optional($zone->mainZone)->iso . ")",
                        'region'  => optional(optional($zone->region)->quadrant)->name ?? "",
                        'area'    => optional($zone->region)->name ?? "",
                        'tire'    => optional($zone->tire)->name ?? "",
                        'zone'    => $zone->name ?? "",
                        'status'  => $zone->active_inactive == 1 ? 'Active' : 'Inactive',
                    ];
                })
                ->toArray();

            $this->totalData = count($zones);

            return $zones;

        } catch (\Throwable $e) {

            Log::channel('commission')->error('ZoneReportExportJob::getReport failed: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Excel headers.
     */
    public function headers(): array
    {
        return [
            'ID',
            'Country',
            'Region',
            'Area',
            'Tire',
            'Zone',
            'Status',
        ];
    }

    /**
     * Total count.
     */
    public function count(): int
    {
        return $this->totalData;
    }
}