<?php

namespace App\Repositories\General;
use App\Interfaces\General\SalesOperationInterface;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\GooglePlaceScrapingJob;
use App\PotentialClient;
use App\Exports\PotentialClientImportErrorExport;
use App\Imports\PotentialClientImport;
use App\Client;
use App\Geofence;
use App\ClientShop;


class SalesOperationInterfaceRepository implements SalesOperationInterface
{
    public function getPotentialClientScrapperList(?string $search, int $perPage)
    {
        return GooglePlaceScrapingJob::when($search, function ($query) use ($search) {
            $query->where('name', 'like', "%{$search}%");
        })->paginate($perPage);
    }

    public function createPotentialClientScrapper(array $data): GooglePlaceScrapingJob
    {
        return GooglePlaceScrapingJob::create($data);
    }

    public function getPotentialClients(array $filters,int $perPage)
    {
        return PotentialClient::query()
            ->with('industry')
            ->withTier()

            ->when($filters['q'] ?? null, function ($query, $term) {
                $query->whereLike(
                    ['client_name','poc_name','poc_position','poc_mobile','poc_landline'],
                    $term
                );
            })

            ->when(!empty($filters['fence']), function ($query) use ($filters) {

                $fence = is_array($filters['fence']) ? $filters['fence'] : [$filters['fence']];

                $query->whereExists(function ($query) use ($fence) {

                    $query->select(DB::raw(1))
                        ->from('geofences')
                        ->whereRaw("
                            ST_Contains(
                                ST_GeomFromText(geofences.area),
                                ST_GeomFromText(CONCAT('POINT(', REPLACE(potential_clients.location, ',', ' '), ')'))
                            )
                        ")
                        ->whereIn('geofences.id', $fence);
                });

            })

            ->when($filters['industry'] ?? null, function ($query, $industry) {
                $query->whereHas('industry', function ($query) use ($industry) {
                    $query->where('industries.id', $industry);
                });
            })

            ->when($filters['order_volume'] ?? null, function ($query, $order_volume) {
                $query->where('order_volume', $order_volume);
            })

            ->when($filters['batch_id'] ?? null, function ($query, $batch_id) {
                $query->where('batch_id', $batch_id);
            })

            ->latest()
            ->paginate($perPage);
    }

    public function findPotentialClient($id)
    {
        return PotentialClient::findOrFail($id);
    }

    public function updatePotentialClient($client, array $data)
    {
        $client->update($data);
        return $client->fresh();
    }

    public function getPotentialClientMap($filters)
    {
        return PotentialClient::query()
            ->withTier()
            ->withIndustry()
            ->when($filters['q'] ?? null, function ($query, $term) {
                $query->whereLike([
                    'client_name',
                    'poc_name',
                    'poc_position',
                    'poc_mobile',
                    'poc_landline'
                ], $term);
            })
            ->active()
            ->latest()
            ->get();
    }

    public function importPotentialClients($file, $batchId, $userId)
    {
        $import = new PotentialClientImport($batchId, $userId);
        $import->import($file);

        return $import;
    }


    public function getActiveStores(array $filters)
    {
        $query = ClientShop::query()
            ->select(
                'client_shops.id',
                'client_shops.client_id',
                'client_shops.name',
                'client_shops.location',
                'zones.name as zone_name',
                'regions.name as region_name',
                'client_user.name as client_name',
                'clients.company_logo_path as logo'
            )
            ->withTier()
            ->excludeClients()
            ->leftJoin('clients', 'clients.id', '=', 'client_shops.client_id')
            ->leftJoin('users as client_user', 'client_user.id', '=', 'clients.user_id')
            ->leftJoin('zones', 'zones.id', '=', 'client_shops.zone_id')
            ->leftJoin('regions', 'regions.id', '=', 'zones.region_id')
            ->whereHas('orders')
            ->where('client_shops.location', '<>', '')
            ->isActive();

        if (!empty($filters['client'])) {
            $query->whereIn('client_id', $filters['client']);
        }

        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {

            $from = now()->parse($filters['date_from'] ?? now()->startOfMonth())->startOfDay();
            $to = now()->parse($filters['date_to'] ?? now())->endOfDay();

            $query->withCount([
                'orders' => function ($q) use ($from, $to) {
                    $q->whereBetween('created_at', [$from, $to]);
                }
            ]);
        }

        return $query->get();
    }

    
}