<?php

namespace App\Jobs;

use App\Client;
use App\Exports\QueueExport;
use Illuminate\Support\Facades\Log;

class ClientReportExportJob extends QueueExport
{
    protected int $chunk = 1000;
    protected int $totalData = 0;
    protected string $file_name = 'client_report';

    public $tries = 3;
    public $timeout = 900;
    public $retryAfter = 60;

    /**
     * Prepare data for Excel export
     */
    public function data(): array
    {
        try {

            $clients = $this->getReport();

            $data = [];

            foreach ($clients as $client) {
                $data[] = [
                    $client['id'] ?? '',
                    $client['client_name'] ?? '',
                    $client['email'] ?? '',
                    $client['mobile_no'] ?? '',
                    $client['region'] ?? '',
                    $client['area'] ?? '',
                    $client['platform'] ?? '',
                    $client['status'] ?? '',
                ];
            }

            return $data;

        } catch (\Throwable $e) {

            Log::error('ClientReportExportJob failed: '.$e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }


    /**
     * Fetch report data
     */
    private function getReport(): array
    {
        try {

            $request = $this->export->filters ?? [];

            $query = Client::query()
                ->with(['user', 'zones.region.quadrant', 'clientSource'])
                ->orderBy('id', 'desc');

            // Search
            if (!empty($request['q'])) {
                $query->whereHas('user', function ($q) use ($request) {
                    $q->where('name', 'like', "%{$request['q']}%")
                        ->orWhere('email', 'like', "%{$request['q']}%");
                });
            }

            // Platform
            if (!empty($request['platforms'])) {
                $query->where('source_id', $request['platforms']);
            }

            // Quadrant
            if (!empty($request['quadrant'])) {
                $query->whereHas('zones', function ($q) use ($request) {
                    $q->whereHas('region', function ($q2) use ($request) {
                        $q2->where('quadrant_id', $request['quadrant']);
                    });
                });
            }

            // Region
            if (!empty($request['region'])) {
                $query->whereHas('zones', function ($q) use ($request) {
                    $q->where('region_id', $request['region']);
                });
            }

            // Status
            if (!empty($request['status'])) {
                $query->where('status', $request['status']);
            }

            $clients = $query
                ->limit($this->chunk)
                ->offset(($this->chunk * ($this->export->page_done ?? 0)))
                ->get()
                ->map(function ($client) {

                    return [
                        'id' => $client->code,
                        'client_name' => $client->user->name ?? '',
                        'email' => $client->email ?? '',
                        'mobile_no' => $client->mobile_number ?? '',
                        'region' => $client->zones->pluck('region.quadrant.name')->unique()->join(','),
                        'area' => $client->zones->pluck('region.name')->unique()->join(','),
                        'platform' => $client->clientSource->name ?? 'NA',
                        'status' => $client->status,
                    ];

                })
                ->toArray();

            $this->totalData = count($clients);

            return $clients;

        } catch (\Throwable $e) {

            Log::error('ClientReportExportJob::getReport failed: '.$e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return [];
        }
    }


    /**
     * Excel Headers
     */
    public function headers(): array
    {
        return [
            "ID",
            "Client Name",
            "Email",
            "Mobile No",
            "Region",
            "Area",
            "Platform",
            "Status"
        ];
    }


    /**
     * Total count
     */
    public function count(): int
    {
        return $this->totalData;
    }
}