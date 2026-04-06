<?php

namespace App\Interfaces\General;

use App\SalesManager;
use App\User;
use App\GooglePlaceScrapingJob;
use App\PotentialClient;
use Illuminate\Http\UploadedFile;

interface SalesOperationInterface
{
    public function getPotentialClientScrapperList(?string $search, int $perPage);
    public function createPotentialClientScrapper(array $data): GooglePlaceScrapingJob;
    public function getPotentialClients(array $filters,int $perPage);
    public function findPotentialClient($id);
    public function updatePotentialClient(PotentialClient $client, array $data);
    public function getPotentialClientMap(array $data);
    public function importPotentialClients( UploadedFile $file,?string $batchId, int $userId);
    public function getActiveStores(array $filters);

}