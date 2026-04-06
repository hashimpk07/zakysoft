<?php

namespace App\Jobs;

use App\GooglePlaceScrapingJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\GooglePlacesExport;
use Illuminate\Support\Facades\Storage;

class GooglePlaceScrapingJobProcessor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $apiKey;
    protected array $placesData = [];

    /**
     * Create a new job instance.
     */
    public function __construct(protected GooglePlaceScrapingJob $scrapingJob)
    {
        $this->apiKey = config('services.map.google.key');
        $this->onQueue('reports');
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $parameters = json_decode($this->scrapingJob->parameters, true);
        $city = $parameters['city'];
        $keyword = $parameters['keyword'];
        $radius = $parameters['radius'] * 1000; // Convert km to meters

        if (!$city || !$keyword) {
            return;
        }

        $location = $this->getCoordinates($city);
        if (!$location) {
            $this->scrapingJob->update(['status' => GooglePlaceScrapingJob::STATUS_FAILED]);
            return;
        }

        $this->fetchPlaces($keyword, $location, $radius);

        $filePath = "scraper_results/{$this->scrapingJob->name}.xlsx"; 
        Excel::store(new GooglePlacesExport($this->placesData), "public/{$filePath}", 'local');

        $this->scrapingJob->update([
            'status' => GooglePlaceScrapingJob::STATUS_COMPLETED,
            'file_path' => 'storage/'. $filePath,
            'completed_at' => now(),
        ]);
    }

    /**
     * Get latitude and longitude for a city.
     */
    private function getCoordinates(string $city): ?string
    {
        $geoUrl = "https://maps.googleapis.com/maps/api/geocode/json";
        $response = Http::get($geoUrl, [
            'address' => $city,
            'key' => $this->apiKey
        ])->json();

        if (empty($response['results'])) {
            return null;
        }

        $lat = $response['results'][0]['geometry']['location']['lat'];
        $lng = $response['results'][0]['geometry']['location']['lng'];

        return "$lat,$lng";
    }

    /**
     * Fetch places and process recursively for pagination.
     */
    private function fetchPlaces(string $keyword, string $location, int $radius, string $nextPageToken = null)
    {
        $url = "https://maps.googleapis.com/maps/api/place/textsearch/json";
        $params = [
            'query' => $keyword,
            'key' => $this->apiKey
        ];

        if ($nextPageToken) {
            $params['pagetoken'] = $nextPageToken;
        } else {
            $params['location'] = $location;
            $params['radius'] = $radius;
        }

        $cacheKey = 'google_places_search_' . md5($url . json_encode($params));
        $response = Cache::remember($cacheKey, now()->addDays(30), function () use ($url, $params) {
            return Http::get($url, $params)->json();
        });

        $places = $response['results'] ?? [];


        foreach ($places as $place) {
            $placeId = $place['place_id'];
    
            $cacheKey = 'google_place_details_' . $placeId;
            $detailsResponse = Cache::remember($cacheKey, now()->addDays(30), function () use ($placeId) {
                return Http::get("https://maps.googleapis.com/maps/api/place/details/json", [
                    'place_id' => $placeId,
                    'fields' => 'international_phone_number,website,types',
                    'key' => $this->apiKey
                ])->json();
            });

            $details = $detailsResponse['result'] ?? [];
    
            $this->placesData[] = [
                'name' => $place['name'] ?? "N/A",
                'address' => $place['formatted_address'] ?? "N/A",
                'location' => "{$place['geometry']['location']['lat']}, {$place['geometry']['location']['lng']}",
                'business_status' => $place['business_status'] ?? "N/A",
                'phone' => $details['international_phone_number'] ?? "N/A",
                'website' => $details['website'] ?? "N/A",
                'type' => isset($details['types']) ? implode(", ", $details['types']) : "N/A"
            ];
        }

        if (!empty($response['next_page_token'])) {
            sleep(2);
            $this->fetchPlaces($keyword, $location, $radius, $response['next_page_token']);
        }
    }
}
