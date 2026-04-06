<?php

namespace App\Interfaces\General;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Client;
use App\ClientShop;
use Illuminate\Http\UploadedFile;

interface SalesManagementInterface
{
    public function getClientList(array $filters, int $perPage);

    public function createUser(array $data);
    public function createClient(array $data);
    public function createBank($client, array $data);
    public function attachCommission($client, $commission);
    public function createAttachments($client, array $documents);
    public function createFallbackRule($client, array $data);
    public function getClientData(Client $client);
    public function updateClient(Client $client, array $data);
    public function updateClientNotes(Client $client, array $data);
    public function getClientDetails(int $id, array $filters,int $perPage);
    public function createBrand(array $data);
    public function getBrandDetails(int $id);
    public function updateBrand(int $id, array $data);

    public function createShop(array $data);
    public function createTimeSlots($shop, array $slots);
    public function attachDeliveryTypes($clientShop, array $types);
    public function createZoneCharge(array $data);
    public function createRadiusCharge(array $data);
    public function getShopDetails(int $id);

    public function updateShop(ClientShop $shop, array $data);
    public function syncDeliveryTypes(ClientShop $shop, array $types);
    public function updateTimeSlot($shop, $data);
    public function deleteZoneCharges($shopId);
    public function deleteRadiusCharges($shopId);
    public function deleteTimeSlots($shopId);

    public function importClientShop(int $clientId, int $userId, UploadedFile $file);

}