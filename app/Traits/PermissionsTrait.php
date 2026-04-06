<?php

// namespace App\Http\Controllers;
namespace App\Traits;

use App\User;
use App\ClientShop;
use App\Zone;
use App\EmpPermissionZonesBranch;
use App\Region;
use App\Client;

trait PermissionsTrait
{
    private function applyOwnerScope($collection, $type = 'order')
    {
        $user = auth()->user();
        if (!$user) return collect([]);

        // Client scope logic
        $employeeClients = $user->employeeClient->pluck('id')->toArray();
        
        if (!empty($employeeClients)) {
            if ($type == 'order') {
                $countBefore = $collection->count();
                $collection = $collection->whereIn('client_id', $employeeClients);
            }
        }

        if ($user->data_permission == User::DATA_PERMISSION_BRANCH_BASED) {
            $permissionShops = $user->dataPermission()->pluck('id')->toArray();
            if ($type == 'order') {
                $collection = $collection->whereIn('shop_id', $permissionShops);
            }
            if ($type == 'captain') {
                $allowedZones = $user->dataPermission()->pluck('zone_id')->unique()->toArray();
                $allowedRegions = Zone::whereIn('id', $allowedZones)->pluck('region_id')->toArray();
                $collection = $collection->filter(function($c) use ($allowedRegions) {
                     return !empty(array_intersect($c->regions, $allowedRegions));
                });
            }
        } elseif ($user->data_permission == User::DATA_PERMISSION_ZONE_BASED) {
            $allowedZones = EmpPermissionZonesBranch::where('user_id', $user->id)->pluck('zone_id')->unique()->toArray();
            
            if ($type == 'order') {
                $allowedShopIds = ClientShop::whereIn('zone_id', $allowedZones)->pluck('id')->toArray();
                $collection = $collection->whereIn('shop_id', $allowedShopIds);
            }
            
            if ($type == 'captain') {
                 $allowedRegions = Zone::whereIn('id', $allowedZones)->pluck('region_id')->toArray();
                 $collection = $collection->filter(function($c) use ($allowedRegions) {
                     return !empty(array_intersect($c->regions, $allowedRegions));
                });
            }
        } elseif ($user->data_permission == User::DATA_PERMISSION_CLIENT_BASED) {
            $allowedClients = $user->dataPermission()->pluck('id')->toArray();
            if ($type == 'order') {
                $collection = $collection->whereIn('client_id', $allowedClients);
            }
            if ($type == 'captain') {
                $allowedZones = ClientShop::whereIn('client_id', $allowedClients)->pluck('zone_id')->unique()->toArray();
                $allowedRegions = Zone::whereIn('id', $allowedZones)->pluck('region_id')->toArray();
                 $collection = $collection->filter(function($c) use ($allowedRegions) {
                     return !empty(array_intersect($c->regions, $allowedRegions));
                });
            }
        } elseif ($user->data_permission == User::DATA_PERMISSION_REGION_BASED) {
            $allowedQuadrants = EmpPermissionZonesBranch::where('user_id', $user->id)->pluck('quadrant_id')->unique()->toArray();
            
            if ($type == 'order') {
                $collection = $collection->whereIn('shop_quadrant_id', $allowedQuadrants);
            }
            
            if ($type == 'captain') {
                 $allowedRegions = Region::whereIn('quadrant_id', $allowedQuadrants)->pluck('id')->toArray();
                 $collection = $collection->filter(function($c) use ($allowedRegions) {
                     return !empty(array_intersect($c->regions, $allowedRegions));
                });
            }
        }

        return $collection->values();
    }
}
