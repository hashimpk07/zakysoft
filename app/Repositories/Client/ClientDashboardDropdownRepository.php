<?php

namespace App\Repositories\Client;

use App\Client;
use App\ClientShop;
use App\DeliveryType;
use Illuminate\Support\Facades\Log;
use App\ClientShopTimeSlot;
use App\Zone;

class ClientDashboardDropdownRepository
{
    public function getClients()
    {
        try {
            return Client::query()
                ->with('user:id,name,email')
               // ->belongsToMe()
                ->select('id', 'user_id')
                ->get()
                ->map(function ($client) {
                    return [
                            'id' => $client->id,
                            'name' => $client->user?->name,
                        ];
                    });
        } catch (\Exception $e) {
            Log::error('Error fetching scheduled orders: ' . $e->getMessage());
            return null; 
        }
    }

    public function getShops()
    {
        try {
            return ClientShop::query()
              //  ->belongsToMe()
                ->addSelect([
                    'id',
                    'name',
                    'client_name' => Client::select('users.name')
                        ->leftJoin('users', 'users.id', 'clients.user_id')
                        ->whereColumn('clients.id', 'client_shops.client_id')
                ])
                ->get();
        } catch (\Exception $e) {
            Log::error('Error fetching scheduled orders: ' . $e->getMessage());
            return null; 
        }
    }

    public function getZones()
    {
        try {
            return Zone::with('region:id,name')
             //   ->belongsToMe()
                ->orderBy('name')
                ->get(['id', 'name', 'region_id']);
        } catch (\Exception $e) {
            Log::error('Error fetching scheduled orders: ' . $e->getMessage());
            return null; 
        }
    }

    public function getTimeSlots()
    {
        try {
            return ClientShopTimeSlot::select('id', 'start_time', 'end_time')->get();
        } catch (\Exception $e) {
            Log::error('Error fetching scheduled orders: ' . $e->getMessage());
            return null; 
        }
    }
}
