<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Captain;
use App\Order;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\CaptainController;
use Illuminate\Support\Facades\Redis;

class TestRedisGeoCommand extends Command
{
    protected $signature = 'test:redis-geo {captain_id} {order_id}';
    protected $description = 'Simulate logLocation for a captain then test fetching via order';

    public function handle()
    {
        $captainId = $this->argument('captain_id');
        $orderId = $this->argument('order_id');

        $captain = Captain::find($captainId);
        $order = Order::with('shop.zone')->find($orderId);

        if (!$captain || !$order) {
            $this->error('Captain or Order not found');
            return;
        }

        $lat = $order->shop->latitude ?? 24.7136;
        $long = $order->shop->longitude ?? 46.6753;
        
        // Add random jitter to simulate captain near shop
        $lat += 0.005;
        $long += 0.005;

        $this->info("Simulating logLocation for Captain {$captainId} at near shop coords ({$lat}, {$long})");

        $request = Request::create('/api/captain/location', 'POST', [
            'data' => [
                'fbtoken' => $captain->accessToken->fb_token ?? '',
                'lat' => $lat,
                'long' => $long,
                'accuracy' => 10,
                'altitude' => 0,
                'speed' => 0,
                'speedaccuracy' => 0,
                'time' => now()->toDateTimeString(),
                'battery' => 100,
            ]
        ]);
        
        // For testing we just bypass the auth and directly call the controller
        $controller = new CaptainController();
        
        // Mock ApiService
        \Facades\App\Services\ApiService::shouldReceive('getCaptainID')->andReturn($captainId);
        
        $response = $controller->logLocation($request);
        
        $this->info("logLocation Response: " . $response->status());
        
        $region_id = $order->region_id ?? $order->shop->zone->region_id ?? 0;
        $this->info("Checking Redis keys for region {$region_id}");
        
        $redisLocs = Redis::geoRadius(
            "captains:locations:{$region_id}",
            $order->shop->longitude ?? 0,
            $order->shop->latitude ?? 0,
            10,
            'km'
        );
        
        $this->info("GEORADIUS found: " . json_encode($redisLocs));
        $this->info("TTL key valid: " . (Redis::exists("captain:{$captainId}:location_validity") ? 'YES' : 'NO'));
        
        $this->info("\n--- Test via HTTP ---");
        $this->info("You can now test via web API: GET /test/redis/captains/{$orderId}");
    }
}
