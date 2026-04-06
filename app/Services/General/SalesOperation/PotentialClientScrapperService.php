<?php

namespace App\Services\General\SalesOperation;

use App\Interfaces\General\SalesOperationInterface;
use App\GooglePlaceScrapingJob;
use App\Jobs\GooglePlaceScrapingJobProcessor;
use App\PotentialClient;
use App\Interfaces\PotentialClientRepositoryInterface;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\PotentialClientImportErrorExport;

final class PotentialClientScrapperService
{
   
    public function __construct(protected readonly SalesOperationInterface $interface) {}

    public function getPotentialClientScrapperList(?string $search, int $perPage)
    {
        return $this->interface->getPotentialClientScrapperList($search, $perPage);
    }

    public function createPotentialClientScrapper(array $validatedData)
    {
        $jobName = $validatedData['name'];

        $parameters = $validatedData;
        unset($parameters['name']);

        $job = $this->interface->createPotentialClientScrapper([
            'name' => $jobName,
            'parameters' => json_encode($parameters),
            'status' => GooglePlaceScrapingJob::STATUS_PENDING
        ]);

        dispatch(new GooglePlaceScrapingJobProcessor($job));

        return $job;
    }

    public function getPotentialClientList(array $filters,int $perPage)
    {
        return $this->interface->getPotentialClients($filters,$perPage);
    }

    public function getPotentialClientDetails(int $id)
    {
        return $this->interface->findPotentialClient($id);
    }

    public function updatePotentialClient(PotentialClient $client, array $data)
    {
        return $this->interface->updatePotentialClient($client, $data);
    }

    public function getPotentialClientMap($filters)
    {
        $clients = $this->interface->getPotentialClientMap($filters);
        $clients = $clients->map(function ($client) {
            return [
                "type" => "Feature",
                "properties" => [
                    "client_name" => $client->client_name,
                    "poc_name" => $client->poc_name,
                    "poc_mobile" => $client->poc_mobile,
                    "industry_type" => $client->industry_type,
                    "order_volume" => $client->order_volume,
                    "tier" => $client->tier,
                    "icon" => "shop"
                ],
                "geometry" => [
                    "type" => "Point",
                    "coordinates" => array_reverse(
                        explode(',', $client->location)
                    )
                ]
            ];
        });

        return [
            "type" => "FeatureCollection",
            "features" => $clients
        ];
    }

    public function importPotentialClients($file, $batchId, $userId)
    {
        $import = $this->interface->importPotentialClients($file, $batchId, $userId);

        if ($import->failures()->isNotEmpty()) {

            $errors = $import->failures()->groupBy(function ($failure) {
                return $failure->row();
            });

            $errors = $errors->map(function ($failed) {

                $errors = $failed->map(function ($error) {
                    return $error->errors();
                })->flatten()->toArray();

                return array_merge($failed[0]->values(), [
                    "errors" => implode(', ', $errors)
                ]);

            });

            $fileName = "potential-client-import-errors-" . now()->timestamp . ".xlsx";

            Excel::store( new PotentialClientImportErrorExport($errors),"public/potential-client-import-errors/$fileName");

            return [
                'status' => false,
                'file_path' => asset("storage/potential-client-import-errors/$fileName")
            ];
        }

        return [
            'status' => true
        ];
    }

    public function getActiveClientData(array $filters)
    {
        $stores = $this->interface->getActiveStores($filters);
        $stores = $stores->map(function ($store) {
            return [
                "type" => "Feature",
                "properties" => [
                    "name" => $store->name,
                    "id" => $store->id,
                    "orders_count" => $store->orders_count ?? -1,
                    "client_name" => $store->client_name,
                    "zone_name" => $store->zone_name,
                    "region_name" => $store->region_name,
                    "icon" => "shop-green",
                    "tier" => $store->tier
                ],
                "geometry" => [
                    "type" => "Point",
                    "coordinates" => array_reverse(explode(',', $store->location))
                ]
            ];
        });

        return [
            "stores" => $stores
        ];
    }

}
