<?php

namespace App\Services\Mobile;

use App\CaptainLocationLog;
use App\Zone;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

final class LocationService
{
    public function __construct(private readonly GeneralService $generalService)
    {
    }
    public function saveLocation(int $captain_id, Request $request)
    {
        $captain = $this->generalService->findCaptainById($captain_id);
        $captain->load('user');

        $lastLog = $this->generalService->getRecentCaptainLocationLog(captain_id: $captain_id);

        $lat = $request->data['lat'] ?? '';
        $long = $request->data['long'] ?? '';
        $accuracy = $request->data['accuracy'] ?? '';
        $altitude = $request->data['altitude'] ?? '';
        $speed = $request->data['speed'] ?? '';
        $speedAccuracy = $request->data['speedaccuracy'] ?? '';
        $time = $request->data['time'] ?? '';
        $battery = $request->data['battery'] ?? '';

        if ($lastLog) {
            $this->checkSpoof(lastLog: $lastLog, lat: $lat, long: $long, captain_id: $captain_id);
        }

        $data = [
            'captain_id' => $captain_id,
            'latitude' => $lat,
            'longitude' => $long,
            'speed' => $speed,
            'battery_level' => $battery,
            'last_updated_at' => $time ? now()->parse($time) : now(),
            'extra_attributes' => [
                'accuracy' => $accuracy,
                'altitude' => $altitude,
                'speedaccuracy' => $speedAccuracy,
            ],
            'zone_id' => Zone::findByLatLong(lat: $lat, long: $long),
        ];

        // Update Redis GEO for each region the captain belongs to
        $captainRegions = $captain->regions()->pluck('regions.id')->toArray();
        foreach ($captainRegions as $regionId) {
            Redis::geoAdd("captains:locations:{$regionId}", $long, $lat, $captain_id);
        }

        // Set a 30-second TTL to easily check if the location is fresh
        Redis::setex("captain:{$captain_id}:location_validity", 30, 'valid');

        return $this->generalService->createCaptainLocationLog(data: $data);
    }

    private function checkSpoof(CaptainLocationLog $lastLog, $lat, $long, $captain_id)
    {
        $distance = $this->haversineDistance((float) $lastLog->latitude, (float) $lastLog->longitude, $lat, $long);

        $timeDiff = Carbon::parse($lastLog->last_updated_at)->diffInSeconds(now());

        // Avoid division by zero - set minimum time difference
        if ($timeDiff == 0) {
            $timeDiff = 1; // Set to 1 second minimum
        }

        $speed = $distance / ($timeDiff / 3600); // km/h
        // 🚨 Multi-condition spoof detection
        $isSpoofed = false;

        if ($speed > 150) {
            $isSpoofed = true;
            $reason = 'Unrealistic speed';
        } elseif ($distance > 2 && $timeDiff < 300) {
            $isSpoofed = true;
            $reason = 'Jumped >2km in <5min';
        } elseif ($speed < 5 && $distance > 3) {
            $isSpoofed = true;
            $reason = 'Low speed but high distance';
        }
        if ($isSpoofed) {
            $googleMapsPrev = "https://www.google.com/maps?q={$lastLog->latitude},{$lastLog->longitude}";
            $googleMapsNew = "https://www.google.com/maps?q={$lat},{$long}";

            Log::channel('spoofing_captains')->warning("🛑 MOCK GPS DETECTED —
                        CaptainID: {$captain_id}
                        Reason: {$reason}
                        Speed: {$speed} km/h
                        Distance: {$distance} km
                        Time Diff: {$timeDiff} seconds
                        Previous Location: {$lastLog->latitude}, {$lastLog->longitude}
                        New Location: {$lat}, {$long}
                        🗺️ Prev: {$googleMapsPrev}
                        🗺️ New: {$googleMapsNew}");
        }

        return true;
    }

    private function haversineDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371; // in km

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));

        return $earthRadius * $angle;
    }
}
