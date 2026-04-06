<?php

namespace App\Console\Commands;

use App\Services\MapboxService;
use Illuminate\Console\Command;

class MapboxCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mapbox:cache 
                            {action : The action to perform (test|stats|clear|clear-all)}
                            {--from= : From coordinates (comma-separated: lng,lat)}
                            {--to= : To coordinates (comma-separated: lng,lat)}
                            {--profile=driving : Mapbox profile}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage Mapbox directions cache';

    /**
     * The MapboxService instance
     *
     * @var MapboxService
     */
    protected $mapboxService;

    /**
     * Create a new command instance.
     *
     * @param MapboxService $mapboxService
     */
    public function __construct(MapboxService $mapboxService)
    {
        parent::__construct();
        $this->mapboxService = $mapboxService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $action = $this->argument('action');

        switch ($action) {
            case 'test':
                return $this->testCache();
            case 'stats':
                return $this->showStats();
            case 'clear':
                return $this->clearSpecific();
            case 'clear-all':
                return $this->clearAll();
            default:
                $this->error("Invalid action: {$action}");
                $this->line('Available actions: test, stats, clear, clear-all');
                return 1;
        }
    }

    /**
     * Test the caching system
     */
    private function testCache()
    {
        $this->info('Testing Mapbox directions caching...');

        // Default test coordinates (adjust as needed)
        $fromLng = 44.017257;
        $fromLat = 26.104734;
        $toLng = 44.028015;
        $toLat = 26.114956;

        if ($this->option('from') && $this->option('to')) {
            $from = explode(',', $this->option('from'));
            $to = explode(',', $this->option('to'));

            if (count($from) !== 2 || count($to) !== 2) {
                $this->error('Coordinates must be in format: lng,lat');
                return 1;
            }

            $fromLng = (float) $from[0];
            $fromLat = (float) $from[1];
            $toLng = (float) $to[0];
            $toLat = (float) $to[1];
        }

        $profile = $this->option('profile');

        $this->line("Testing route from [{$fromLng}, {$fromLat}] to [{$toLng}, {$toLat}] with profile: {$profile}");

        try {
            // First call - should fetch from Mapbox
            $this->info('First call (should fetch from Mapbox API)...');
            $start = microtime(true);
            $result1 = $this->mapboxService->getRoute($fromLng, $fromLat, $toLng, $toLat, $profile);
            $time1 = round((microtime(true) - $start) * 1000, 2);

            if (!$result1 || !isset($result1['routes'])) {
                $this->error('Failed to get route from Mapbox');
                return 1;
            }

            $this->info("✅ First call successful in {$time1}ms");
            $this->line("   Distance: " . ($result1['routes'][0]['distance'] ?? 'N/A') . " meters");
            $this->line("   Duration: " . ($result1['routes'][0]['duration'] ?? 'N/A') . " seconds");

            // Second call - should use cache
            $this->info('Second call (should use Redis cache)...');
            $start = microtime(true);
            $result2 = $this->mapboxService->getRoute($fromLng, $fromLat, $toLng, $toLat, $profile);
            $time2 = round((microtime(true) - $start) * 1000, 2);

            if (!$result2 || !isset($result2['routes'])) {
                $this->error('Failed to get cached route');
                return 1;
            }

            $this->info("✅ Second call successful in {$time2}ms");

            // Compare results
            $distance1 = $result1['routes'][0]['distance'] ?? 0;
            $distance2 = $result2['routes'][0]['distance'] ?? 0;

            if ($distance1 === $distance2) {
                $speedup = $time1 > 0 ? round(($time1 - $time2) / $time1 * 100, 1) : 0;
                $this->info("✅ Cache working correctly! Speed improvement: {$speedup}%");
                $this->line("   First call: {$time1}ms (API)");
                $this->line("   Second call: {$time2}ms (Cache)");
            } else {
                $this->warn('Results differ between API and cache');
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("Test failed: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Show cache statistics
     */
    private function showStats()
    {
        $this->info('Mapbox Cache Statistics:');

        try {
            $stats = $this->mapboxService->getCacheStats();

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Cache Driver', config('cache.default')],
                    ['Total Cached Routes', $stats['total_cached_routes'] ?? 'N/A'],
                    ['Cache Prefix', $stats['cache_prefix'] ?? 'N/A'],
                    ['Cache TTL (hours)', $stats['cache_ttl_hours'] ?? 'N/A'],
                    ['Coordinate Precision', $stats['coordinate_precision'] ?? 'N/A'],
                ]
            );

            if (isset($stats['error'])) {
                $this->warn('Note: ' . $stats['error']);
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("Failed to get cache statistics: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Clear specific cached route
     */
    private function clearSpecific()
    {
        if (!$this->option('from') || !$this->option('to')) {
            $this->error('--from and --to options are required for clearing specific cache');
            $this->line('Example: --from=44.017,26.105 --to=44.028,26.115');
            return 1;
        }

        $from = explode(',', $this->option('from'));
        $to = explode(',', $this->option('to'));

        if (count($from) !== 2 || count($to) !== 2) {
            $this->error('Coordinates must be in format: lng,lat');
            return 1;
        }

        $fromLng = (float) $from[0];
        $fromLat = (float) $from[1];
        $toLng = (float) $to[0];
        $toLat = (float) $to[1];
        $profile = $this->option('profile');

        try {
            $cleared = $this->mapboxService->clearCachedRoute($fromLng, $fromLat, $toLng, $toLat, $profile);

            if ($cleared) {
                $this->info("✅ Cleared cached route from [{$fromLng}, {$fromLat}] to [{$toLng}, {$toLat}]");
            } else {
                $this->warn("No cached route found for the specified coordinates");
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("Failed to clear cache: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Clear all cached routes
     */
    private function clearAll()
    {
        if (!$this->confirm('Are you sure you want to clear ALL cached routes?')) {
            $this->info('Operation cancelled.');
            return 0;
        }

        try {
            $deletedCount = $this->mapboxService->clearAllCachedRoutes();

            $this->info("✅ Cleared {$deletedCount} cached routes");

            return 0;

        } catch (\Exception $e) {
            $this->error("Failed to clear all cache: " . $e->getMessage());
            return 1;
        }
    }
}