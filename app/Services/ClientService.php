<?php

namespace App\Services;

use App\Client;
use App\Region;
use App\Quadrant;
use App\ClientSource;
use App\DeliveryType;
use App\ClientDocument;
use App\TimeSlot;
use App\ClientBrand;
use App\ClientShop;
use Facades\App\Services\Search;


class ClientService
{
    public function clients($request): array
    {
        $clients = Client::query()
            ->with('user', 'zones.region.quadrant', 'clientSource')
            ->when($request->get('name'), function ($query, $terms) {
                $query->whereLike(['user.name', 'user.email'], $terms);
            })
            ->when($request->get('platforms'), function ($query, $platform) {
                $query->where('source_id', $platform);
            })
            ->when($request->get('quadrant'), function ($query, $quadrant) {
                $query->whereHas('zones', function ($query) use ($quadrant) {
                    $query->whereHas('region', function ($query) use ($quadrant) {
                        $query->where('quadrant_id', $quadrant);
                    });
                });
            })
            ->when($request->get('region'), function ($query, $region) {
                $query->whereHas('zones', function ($query) use ($region) {
                    $query->where('region_id', $region);
                });
            })
            ->when($status = $request->get('status'), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->paginate($request->get('per_page', 10))
            ->withQueryString();

        $clients->getCollection()->transform(function ($client) {
            return [
                'id' => $client->id,
                'name' => $client->user->name ?? null,
                'mobile' => $client->mobile_number,
                'email' => $client->user->email ?? null,
                'quadrants' => $client->zones->pluck('region.quadrant.name')->unique()->values()->join(','),
                'regions' => $client->zones->pluck('region.name')->unique()->values()->join(','),
                'platform' => $client->clientSource->name ?? 'N/A',
                'status' => $client->status,
            ];
        });




        return [
            'clients' => $clients,

        ];
    }

    public function fetchClientDetails($clientId, $request): array
    {
        $search = $request->input('search');
        $status = $request->input('status');
        $limit = $request->input('per_page', 10);
        $shops = ClientShop::with('dispatchRuleForExpress', 'brand')->where('client_id', $clientId)
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', $search . '%')
                        ->orWhere('id', '=', ltrim($search, "0"));
                    $q->whereLike(['name', 'id'], $search);
                });
            });

        // Handle status filtering outside the when callback
        if ($status === '0') {
            $shops = $shops->where('status', ClientShop::STATUS_INACTIVE);
        } elseif ($status === '1') {
            $shops = $shops->where('status', ClientShop::STATUS_ACTIVE);
        }

        $shops = $shops->paginate($limit);



        $shops->getCollection()->transform(function ($shop) {
            return [
                'id' => sprintf('%04d', $shop->id),
                'shop_created_at' => $shop->created_at->format('d M, Y h:i A'),
                'name' => $shop->name,
                'client' => $shop->client->user->name ?? null,
                'location' => $shop->location,
                'brand' => $shop->brand ? $shop->brand->name_en . ' (BRD' . str_pad($shop->brand->id, 3, '0', STR_PAD_LEFT) . ')' : 'No Brand',
                'auto_assignable' => $shop->auto_assignable ? ($shop->dispatchRuleForExpress ? $shop->dispatchRuleForExpress->name : 'Not Assigned Rule') : 'Manual',
                'created_by' => $shop->createdBy ? $shop->createdBy->name : '',
                'status' => $shop->status == ClientShop::STATUS_ACTIVE ? 'Active' : 'Inactive',
                'zones' => $shop->shopZones->pluck('zone.name')->toArray(),
                'zone' => $shop->region,
                'express_time' => $shop->express_time ?? 0,
                'reference_id' => $shop->partner_id ?? null,
            ];
        });


        return [
            'shops' => $shops,
        ];
    }
}
