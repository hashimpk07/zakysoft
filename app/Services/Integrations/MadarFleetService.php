<?php

namespace App\Services\Integrations;

use App\Order;
use App\OrderStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class MadarFleetService
{
    protected $baseUrl;
    protected $apiKey;
    protected $appId;

    protected $shipperId;

    public function __construct()
    {
        $this->baseUrl = config('madar_fleet.base_url');
        $this->apiKey = config('madar_fleet.api_key');
        $this->appId = config('madar_fleet.app_id');
        $this->shipperId = config('madar_fleet.shipper_id');
    }

    /**
     * Verify (Create) Order in Madar Fleet
     */
    public function verifyOrder(Order $order)
    {
        if (!config('madar_fleet.enable')) {
            return;
        }

        $endpoint = 'order/verify';
        $url = rtrim($this->baseUrl, '/') . '/' . $endpoint;

        $payload = [
            'shipperId' => $this->shipperId, // Assuming shipperId is client_id
            'trackingNumber' => (string) $order->id, // Making unique tracking number
            'mobileNumber' => $order->customer_number,
            'expectedDeliveryDate' => $order->delivery_date ?? now()->toDateString(),
            'referenceNumber' => $order->client_order_id,
            'customerName' => $order->customer_name,
            'status' => 'Created', // Initial status
             // Add other fields if mapped
        ];

        $this->sendRequest($url, $payload, $order->id, $endpoint);
    }

    /**
     * Update Order Status in Madar Fleet
     */
    public function updateOrder(Order $order)
    {
        if (!config('madar_fleet.enable')) {
            return;
        }
        
        $mappedStatus = $this->mapStatus($order->status_id);
        
        if (!$mappedStatus) {
             return; // Status not supported for sync
        }

        $endpoint = 'order/update-order';
        $url = rtrim($this->baseUrl, '/') . '/' . $endpoint;

        // Requirement says "The system shall reject invalid status values" so we must be careful what we send.
        // Valid: Created, InTransit, Delivered, Cancelled
        
        $payload = [
            'shipperId' => $this->shipperId,
            'trackingNumber' => (string) $order->id, // Assuming using ID as trackingNum
            'status' => $mappedStatus,
        ];

         $this->sendRequest($url, $payload, $order->id, $endpoint, 'PUT');
    }
    
    /**
     * Configure Callback URL
     */
    public function configureCallback($callbackUrl)
    {
         $endpoint = 'fleet/update-config';
         $url = rtrim($this->baseUrl, '/') . '/' . $endpoint;
         
         $payload = [
             'url' => $callbackUrl,
             'appKey' => $this->apiKey,
             'appId' => $this->appId
         ];
         
         // This might not need an order_id
         $this->sendRequest($url, $payload, null, $endpoint, 'PUT');
    }

    protected function sendRequest($url, $payload, $orderId, $endpoint, $method = 'POST')
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => $this->apiKey,
                'api_key' => $this->apiKey,
            ])->$method($url, $payload);

            $this->log($orderId, $endpoint, $method, $payload, $response->json(), $response->status());

            return $response;

        } catch (\Exception $e) {
            Log::error("Madar Fleet API Error: " . $e->getMessage());
            $this->log($orderId, $endpoint, $method, $payload, ['error' => $e->getMessage()], 500);
            return null;
        }
    }

    protected function log($orderId, $endpoint, $method, $request, $response, $statusCode)
    {
        DB::table('madar_fleet_logs')->insert([
            'order_id' => $orderId,
            'endpoint' => $endpoint,
            'method' => $method,
            'request_payload' => json_encode($request),
            'response_payload' => json_encode($response),
            'status_code' => $statusCode,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function mapStatus($localStatusId)
    {
        // Accepted values: Created, InTransit, Delivered, Cancelled
        switch ($localStatusId) {
            case OrderStatus::NEW_ORDER: // Or whatever is local "Created"
                return 'Created';
            case OrderStatus::SHIPPED:
            // case OrderStatus::START_RIDE:
            // case OrderStatus::PICKED:
                 return 'InTransit';
            case OrderStatus::DELIVERED:
                return 'Delivered';
            case OrderStatus::CANCEL:
            case OrderStatus::CLIENT_RETURN_ACCEPTED:
                 return 'Cancelled';
            default:
                return null;
        }
    }
}
