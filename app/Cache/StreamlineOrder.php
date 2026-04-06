<?php
namespace App\Cache;

use App\Order;
use App\User;

class StreamlineOrder extends Cache {

  protected $data = [];

  public function __construct($data = []) {
    $this->data = $data;

    parent::__construct();
  }

  public function withUserPermission() {
    $user = auth()->user();

    if(!$user || $user->data_permission == User::DATA_PERMISSION_ALL_ACCESS_BASED) {
      return $this;
    }

    $sets = [];
    $permission_ids = $user->dataPermission()->pluck('id');
    
    $key = null;
    if ($user->data_permission == User::DATA_PERMISSION_BRANCH_BASED) {
      $key = 'shop_id';
    } elseif ($user->data_permission == User::DATA_PERMISSION_ZONE_BASED) {
      $key = 'zone_id'; // Note: StreamlineOrder doesn't have zone_id in filterable yet.
    } elseif ($user->data_permission == User::DATA_PERMISSION_REGION_BASED) {
      $key = 'shop_quadrant_id';
    } elseif ($user->data_permission == User::DATA_PERMISSION_CLIENT_BASED) {
      $key = 'client_id';
    }

    if ($key) {
        foreach($permission_ids as $v) {
          $sets[] = "{$this->key()}:by:$key:$v";
        }
        $this->setSets([$sets]);
    }

    return $this;
  }

  public static function fromOrder(Order $order) {
    $order->loadMissing(['assignAttempts', 'captain.user', 'client.user', 'shop.client', 'shop.zone.region', 'progress']);
    $instance = new static();
    
    $instance->data = [
        "id" => $order->id,
        "client_order_id" => $order->client_order_id,
        "status_id" => $order->status_id,
        "client_id" => $order->client_id,
        "shop_id" => $order->shopname, // shopname is the FK for shop_id
        "shopname" => $order->shopname, // Required for StreamLineMapV2.vue flyTo logic
        "captain_id" => $order->captain_id,
        "created_at" => $order->created_at ? \Carbon\Carbon::parse($order->created_at)->timestamp : null,
        "dispatch_at" => $order->dispatch_at ? \Carbon\Carbon::parse($order->dispatch_at)->timestamp : null,
        "delivery_date" => $order->delivery_date ? \Carbon\Carbon::parse($order->delivery_date)->timestamp : null,
        "delivery_type" => $order->delivery_type,
        "scheduled_delivery_time_slot_id" => $order->scheduled_delivery_time_slot_id,
        
        "amount" => $order->amount,
        "delivery_payment_mode" => $order->delivery_payment_mode,
        "customer_number" => $order->customer_number,
        "shop_to_delivery_km" => $order->shop_to_delivery_km,
        "location" => $order->location,
        
        // Flattened Relationships for Search/Filter
        "client_name" => $order->client->user->name ?? '',
        "client_image" => $order->client->company_logo_path ?? '',
        "brand_logo" => $order->shop->logo ?? '',
        "shop_name" => $order->shop->name ?? '',
        "shop_region_name" => $order->shop->zone->region->name ?? '',
        "shop_zone_name" => $order->shop->zone->name ?? '',
        
        "shop_quadrant_id" => $order->shop->zone->region->quadrant_id ?? null,
        "shop_region_id" => $order->shop->zone->region_id ?? null,
        "zone_id" => $order->shop->zone_id ?? null,
        
        "has_client_chat" => $order->openComplaint !== null,
        "auto_assignable" => $order->shop->auto_assignable ?? 0,
        "company_id" => $order->captain->third_party_logistic_company_id ?? null,
        
        // Helper attributes used in View/Controller
        "time_left" => $order->endTime(), 
        "time_start" => $order->startTime(), 
        "created_formatted_at" => $order->created_at ? $order->created_at->format('d-m-Y h:i:s A') : null,
        "status_updated_at" => $order->delivery_date ? $order->delivery_date->format('h:i:s A') : null,
        
        // Captain & Assignment properties
        "assign_attempts_count" => $order->assign_attempts_count ?? $order->assignAttempts->count() ?? 0,
        "captain_name" => $order->captain ? ($order->captain->user->name ?? $order->captain->name ?? null) : null,
        "last_assign_attempt_note" => $order->assignAttempts ? (collect($order->assignAttempts)->last()->note ?? null) : null,

        "progress" => $order->progress ? [
            "name" => $order->progress->name,
            "status_class" => $order->progress->status_class
        ] : null,
        
        "shop" => [
            "id" => $order->shopname,
            "name" => $order->shop->name ?? '',
            "client" => $order->client->user->name ?? '',
            "location" => $order->shop->location ?? '',
            "logo" => $order->shop->logo ?? $order->shop->client->company_logo_path ?? '',
            "client_id" => $order->shop->client_id ?? null,
            "region" => [
                "id" => $order->shop->zone->region_id ?? null,
                "name" => $order->shop->zone->region->name ?? '',
                "quadrant" => [
                     "id" => $order->shop->zone->region->quadrant_id ?? null
                ]
            ]
        ],
        
        "client" => [
            "id" => $order->client_id,
            "user" => ["name" => $order->client->user->name ?? '']
        ],

        "captain" => $order->captain ? [
            "id" => $order->captain->id,
            "name" => $order->captain->user->name ?? $order->captain->name ?? "N/A",
            "phone_number" => $order->captain->phone_number,
        ] : null,

        "open_ticket" => $order->openComplaint ? [
            "id" => $order->openComplaint->id,
            "order_id" => $order->openComplaint->order_id
        ] : null,
        
        "payment" => $order->payment, 
    ];
    return $instance;
  }

  public function filterable() {
    return [
       "id",
       "client_id",
       "shop_id",
       "status_id",
       "captain_id",
       "delivery_type",
       "has_client_chat", 
       "auto_assignable",
       "shop_quadrant_id",
       "shop_region_id",
       "zone_id", // Added zone_id to filterable
       "company_id"
    ];
  }

  public function searchables() {
      return [
          "client_order_id" => "TEXT",
          "id" => "TEXT",
          "customer_number" => "TEXT",
          "client_name" => "TEXT",
          "shop_name" => "TEXT",
          "shop_region_name" => "TEXT"
      ];
  }
}
