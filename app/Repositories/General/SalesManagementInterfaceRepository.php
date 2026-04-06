<?php

namespace App\Repositories\General;
use App\Interfaces\General\SalesManagementInterface;


use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Client;
use App\User;
use Facades\App\Services\Search;
use App\ClientDocument;
use App\ClientShop;
use App\ClientBrand;
use App\TimeSlot;
use App\ClientShopZone;
use App\ClientShopDeliveryChargeBasedOnRadius;
use App\ClientShopTimeSlot;
use App\Exports\ClientShopImportErrorExport;
use App\Imports\ClientShopImport;
use Maatwebsite\Excel\Facades\Excel;

class SalesManagementInterfaceRepository implements SalesManagementInterface
{
   public function getClientList(array $filters, int $perPage)
    {
        return Client::query()
                ->select([
                    'id',
                    'code',
                    'user_id',
                    'email',
                    'mobile_number',
                    'status',
                    'created_at',
                    'source_id'
                ])
                ->with(['user', 'zones.region.quadrant'])
                ->when($filters['q'] ?? null, function ($query, $terms) {
                    $query->whereHas('user', function ($q) use ($terms) {
                        $q->where('name', 'like', "%$terms%")->orWhere('email', 'like', "%$terms%");
                    });
                })
                
                ->when($filters['platforms'] ?? null, function ($query, $platform) {
                    $query->where('source_id', $platform);
                })

                ->when($filters['quadrant'] ?? null, function ($query, $quadrant) {
                    $query->whereHas('zones.region', function ($q) use ($quadrant) {
                        $q->where('quadrant_id', $quadrant);
                    });
                })

                ->when($filters['region'] ?? null, function ($query, $region) {
                    $query->whereHas('zones', function ($q) use ($region) {
                        $q->where('region_id', $region);
                    });
                })

                ->when($filters['status'] ?? null, function ($query, $status) {
                    $query->where('status', $status);
                })

        ->paginate($perPage);
    }


    public function createUser(array $data)
    {
        return User::create($data);
    }

    public function createClient(array $data)
    {
        return Client::create($data);
    }

    public function createBank($client, array $data)
    {
        return $client->bank()->create($data);
    }

    public function attachCommission($client, $commission)
    {
        return $client->commission()->attach($commission);
    }

    public function createAttachments($client, array $documents)
    {
        return $client->attachments()->createMany($documents);
    }

    public function createFallbackRule($client, array $data)
    {
        return $client->fallbackRule()->create($data);
    }
    public function getClientData($client)
    {
        return $client->load(['user','zones.region.quadrant','attachments','bank','commission','fallbackRule']);
    }

    public function updateClient(Client $client, array $data)
    {
        $client->update($data);
        return $client;
    }

    public function updateClientNotes(Client $client, array $data)
    {
        $client->update(['notes' => $data['notes']]);
        return $client->fresh();
    }

    public function getClientDetails($id, array $filters,int $perPage)
    {
        $fromDate = $filters['from_date'] ?? null;
        $toDate = $filters['to_date'] ?? null;
        $brandSearch = $filters['brand_search'] ?? null;
        $search = $filters['search'] ?? null;

        $client = Client::query()
            ->with(
                'user',
                'zones',
                'transactions',
                'attachments',
                'contactPosition',
                'commission'
            )
            ->withCount(
                'order',
                'delivered',
                'shipped',
                'returnedClient',
                'assigned'
            )
            ->findOrFail($id);

        $clientdocs = ClientDocument::where('client_id', $id)->first();
        $client = Search::searchClient($fromDate,$toDate,$client,$id);
        $timeSlots = TimeSlot::all();

        $clientBrands = ClientBrand::where('client_id', $id)
            ->when($brandSearch, function($query) use ($brandSearch) {
                $query->where(function($q) use ($brandSearch) {
                    $q->where('name_en', 'like', "%{$brandSearch}%")
                    ->orWhere('name_ar', 'like', "%{$brandSearch}%");
                });
            })
            ->paginate($perPage);

        $shops = ClientShop::with('dispatchRuleForExpress','brand')
            ->where('client_id', $id)
            ->when($search, function($query) use ($search){
                $query->where('name','like',$search.'%')
                    ->orWhere('id','=',ltrim($search,"0"));
            })
        ->paginate($perPage);

        return [
            'client' => $client,
            'documents' => $clientdocs,
            'time_slots' => $timeSlots,
            'brands' => $clientBrands,
            'shops' => $shops
        ];

    }

    public function createBrand(array $data)
    {
        return ClientBrand::create($data);
    }

    public function getBrandDetails(int $id)
    {
        return ClientBrand::findOrFail($id);
    }

    public function updateBrand($id, array $data)
    {
        $brand = $this->getBrandDetails($id);
        $brand->update($data);
        return $brand;
    }


    public function createShop(array $data)
    {
        return ClientShop::create($data);
    }

    public function attachDeliveryTypes($clientShop, array $types)
    {
        return $clientShop->deliveryTypes()->attach($types);
    }

     public function createTimeSlots($shop, array $slots)
    {
        return $shop->timeSlots()->createMany($slots);
    }

    public function createZoneCharge(array $data)
    {
        return ClientShopZone::create($data);
    }

    public function createRadiusCharge(array $data)
    {
        return ClientShopDeliveryChargeBasedOnRadius::create($data);
    }
    
    public function getShopDetails(int $id)
    {
        return ClientShop::with(['shopZones','deliveryTypes','timeSlots','shopRadius'])->findOrFail($id);
    }


     public function updateShop(ClientShop $shop, array $data)
    {
        return $shop->update($data);
    }

    public function syncDeliveryTypes(ClientShop $shop, array $types)
    {
        return $shop->deliveryTypes()->sync($types);
    }

    public function updateTimeSlot($shop, $data)
    {
        return $shop->timeSlots()->createMany($data);
    }
    public function deleteZoneCharges($shopId)
    {
        ClientShopZone::where('client_shop_id', $shopId)->delete();
    }
    public function deleteRadiusCharges($shopId)
    {
        ClientShopDeliveryChargeBasedOnRadius::where('client_shop_id', $shopId)->delete();
    }
    public function deleteTimeSlots($shopId)
    {
        ClientShopTimeSlot::where('client_shop_id', $shopId)->delete();
    }

    public function importClientShop(int $clientId, int $userId, $file)
    {
        $import = new ClientShopImport($clientId, $userId);

        $import->import($file);

        if ($import->failures()->isNotEmpty()) {

            $errors = $import->failures()->map(function ($failed) {

                $values = collect($failed->values())
                    ->filter(function ($value, $key) {
                        return !is_int($key);
                    })->all();

                return array_merge($values, [
                    "errors" => implode(', ', $failed->errors())
                ]);
            });

            $file_name = "client-shop-import-errors-" . now()->timestamp . ".xlsx";

            Excel::store(new ClientShopImportErrorExport($errors),"public/zone-import-errors/$file_name"
            );

            return [
                "status" => false,
                "file_path" => asset("storage/zone-import-errors/$file_name"),
                "errors" => $errors
            ];
        }

        return [
            "status" => true
        ];
    }

   
}