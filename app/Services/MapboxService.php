<?php

namespace App\Services;

use App\ExternalApiUsageLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MapboxService
{
    protected $accessToken;
    protected $cachePrefix = 'mapbox_directions:';
    protected $cacheTtl = 60 * 60 * 24 * 30; // 30 days in seconds

    public function __construct()
    {
        $this->accessToken = config('map-box.access_token');

        if (!$this->accessToken) {
            throw new \Exception('Mapbox access token not configured. Please set MAPBOX_ACCESS_TOKEN in your environment.');
        }
    }

    /**
     * Get route directions between two points with Redis caching
     * 
     * @param float $fromLng
     * @param float $fromLat
     * @param float $toLng
     * @param float $toLat
     * @param string $profile
     * @return array
     * @throws \Exception
     */
    public function getRoute($fromLng, $fromLat, $toLng, $toLat, $profile = 'driving')
    {
        // Create cache key from coordinates (rounded to 4 decimals for ~11m precision)
        // This matches the precision used in the frontend DirectionsCache
        $cacheKey = $this->generateCacheKey($fromLng, $fromLat, $toLng, $toLat, $profile);

        // Try to get from cache first
        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($fromLng, $fromLat, $toLng, $toLat, $profile) {
            return $this->fetchDirectionsFromMapbox($fromLng, $fromLat, $toLng, $toLat, $profile);
        });
    }

    /**
     * Generate cache key for consistent caching
     * 
     * @param float $fromLng
     * @param float $fromLat
     * @param float $toLng
     * @param float $toLat
     * @param string $profile
     * @return string
     */
    private function generateCacheKey($fromLng, $fromLat, $toLng, $toLat, $profile = 'driving')
    {
        // Round coordinates to 4 decimal places for consistent caching
        // This matches the frontend DirectionsCache precision
        $fromLngRounded = round($fromLng, 4);
        $fromLatRounded = round($fromLat, 4);
        $toLngRounded = round($toLng, 4);
        $toLatRounded = round($toLat, 4);

        return sprintf(
            '%s%s_%s_%s_%s_%s',
            $this->cachePrefix,
            $profile,
            $fromLngRounded,
            $fromLatRounded,
            $toLngRounded,
            $toLatRounded
        );
    }

    /**
     * Fetch directions from Mapbox API
     * 
     * @param float $fromLng
     * @param float $fromLat
     * @param float $toLng
     * @param float $toLat
     * @param string $profile
     * @return array
     * @throws \Exception
     */
    private function fetchDirectionsFromMapbox($fromLng, $fromLat, $toLng, $toLat, $profile = 'driving')
    {
        $url = sprintf(
            'https://api.mapbox.com/directions/v5/mapbox/%s/%s,%s;%s,%s',
            $profile,
            $fromLng,
            $fromLat,
            $toLng,
            $toLat
        );

        $queryParams = [
            'geometries' => 'geojson',
            'access_token' => $this->accessToken
        ];

        Log::info('Fetching directions from Mapbox API', [
            'from' => [$fromLng, $fromLat],
            'to' => [$toLng, $toLat],
            'profile' => $profile,
            'url' => $url
        ]);

        $startTime = microtime(true);
        $response = Http::timeout(30)->get($url, $queryParams);
        $responseTime = round((microtime(true) - $startTime) * 1000);

            try {
                $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
                $caller = 'unknown';
                foreach ($backtrace as $trace) {
                    if (isset($trace['class'])) {
                        $class = $trace['class'];
                        if (str_contains($class, 'App\Services\MapboxService') ||
                            str_contains($class, 'Illuminate\\') ||
                            str_contains($class, 'Facade\\') ||
                            str_contains($class, 'Symfony\\')) {
                            continue;
                        }
                        $caller = $class . '@' . ($trace['function'] ?? 'unknown');
                        break;
                    }
                }

                ExternalApiUsageLog::create([
                    'provider' => 'mapbox',
                    'service_type' => 'directions',
                    'caller' => $caller,
                    'endpoint' => $url,
                    'method' => 'GET',
                    'status_code' => $response->status(),
                    'request_params' => $queryParams,
                    'response_time_ms' => $responseTime,
                    'metadata' => [
                        'from' => [$fromLng, $fromLat],
                        'to' => [$toLng, $toLat],
                        'profile' => $profile
                    ]
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to log API usage in MapboxService: ' . $e->getMessage());
            }

        if (!$response->successful()) {
            $error = $response->json();
            Log::error('Mapbox API request failed', [
                'status' => $response->status(),
                'error' => $error,
                'from' => [$fromLng, $fromLat],
                'to' => [$toLng, $toLat]
            ]);

            throw new \Exception('Mapbox API request failed: ' . ($error['message'] ?? 'Unknown error'));
        }

        $data = $response->json();

        // Validate response structure
        if (!isset($data['routes']) || empty($data['routes'])) {
            throw new \Exception('No routes found in Mapbox response');
        }

        Log::info('Successfully fetched directions from Mapbox API', [
            'from' => [$fromLng, $fromLat],
            'to' => [$toLng, $toLat],
            'route_count' => count($data['routes'])
        ]);

        return $data;
    }

    /**
     * Clear cached route for specific coordinates
     * 
     * @param float $fromLng
     * @param float $fromLat
     * @param float $toLng
     * @param float $toLat
     * @param string $profile
     * @return bool
     */
    public function clearCachedRoute($fromLng, $fromLat, $toLng, $toLat, $profile = 'driving')
    {
        $cacheKey = $this->generateCacheKey($fromLng, $fromLat, $toLng, $toLat, $profile);
        return Cache::forget($cacheKey);
    }

    /**
     * Clear all cached routes (for maintenance)
     * Note: This only works with Redis cache driver
     * 
     * @return int Number of deleted keys
     */
    public function clearAllCachedRoutes()
    {
        if (config('cache.default') === 'redis') {
            try {
                // Use the cache Redis connection (database 1) to match where Laravel stores cache
                $redis = \Illuminate\Support\Facades\Redis::connection('cache');
                $pattern = config('cache.prefix') . $this->cachePrefix . '*';
                $keys = $redis->keys($pattern);

                if (!empty($keys)) {
                    $deletedCount = $redis->del($keys);
                    Log::info('Cleared Redis cache keys', [
                        'pattern' => $pattern,
                        'deleted_count' => $deletedCount,
                        'connection' => 'cache (db1)'
                    ]);
                    return $deletedCount;
                }

                Log::info('No Redis cache keys found to clear', ['pattern' => $pattern]);
                return 0;
            } catch (\Exception $e) {
                Log::error('Failed to clear Redis cache', ['error' => $e->getMessage()]);
                return 0;
            }
        }

        // For other cache drivers, this would require custom implementation
        Log::warning('clearAllCachedRoutes only supports Redis cache driver');
        return 0;
    }

    /**
     * Get cache statistics
     * 
     * @return array
     */
    public function getCacheStats()
    {
        if (config('cache.default') === 'redis') {
            try {
                // Get the cache store directly for better compatibility
                $cache = Cache::getStore();

                // Try multiple methods to get Redis connection and keys
                $totalCachedRoutes = 0;
                $cachePrefix = config('cache.prefix', '');
                $fullPrefix = $cachePrefix . $this->cachePrefix;

                try {
                    // Use the cache Redis connection (database 1) to match where Laravel stores cache
                    $redis = \Illuminate\Support\Facades\Redis::connection('cache');

                    // Try different patterns to find our cached routes
                    $patterns = [
                        $fullPrefix . '*',        // Full prefix with Laravel cache prefix
                        '*mapbox_directions*',    // Any key containing our cache prefix
                    ];

                    $totalCachedRoutes = 0;
                    foreach ($patterns as $pattern) {
                        $keys = $redis->keys($pattern);
                        if (count($keys) > 0) {
                            $totalCachedRoutes = count($keys);
                            break;
                        }
                    }

                } catch (\Exception $e1) {
                    try {
                        // Fallback: Try default Redis connection (database 0)
                        $redis = \Illuminate\Support\Facades\Redis::connection();
                        $pattern = $fullPrefix . '*';
                        $keys = $redis->keys($pattern);
                        $totalCachedRoutes = count($keys);

                        Log::info('Got Redis cache key count from default connection', [
                            'pattern' => $pattern,
                            'count' => $totalCachedRoutes,
                            'connection' => 'default (db0)'
                        ]);
                    } catch (\Exception $e2) {
                        // If both methods fail, we can't get the count but cache still works
                        Log::warning('Could not get Redis key count from either connection', [
                            'cache_error' => $e1->getMessage(),
                            'default_error' => $e2->getMessage()
                        ]);
                        $totalCachedRoutes = 'Unable to count (but cache is working)';
                    }
                }

                return [
                    'total_cached_routes' => $totalCachedRoutes,
                    'cache_prefix' => $this->cachePrefix,
                    'full_cache_prefix' => $fullPrefix,
                    'laravel_cache_prefix' => $cachePrefix,
                    'cache_ttl_hours' => $this->cacheTtl / 3600,
                    'coordinate_precision' => '4 decimal places (~11m accuracy)',
                    'cache_store_class' => get_class($cache)
                ];
            } catch (\Exception $e) {
                Log::error('Failed to get Redis cache stats', ['error' => $e->getMessage()]);
                return [
                    'cache_driver' => config('cache.default'),
                    'error' => 'Failed to get Redis statistics: ' . $e->getMessage()
                ];
            }
        }

        return [
            'cache_driver' => config('cache.default'),
            'note' => 'Statistics only available with Redis cache driver'
        ];
    }
}