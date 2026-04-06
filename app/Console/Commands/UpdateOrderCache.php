<?php
namespace App\Console\Commands;

use App\Cache\Order;
use App\Order as AppOrder;
use App\OrderStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class UpdateOrderCache extends Command
{
    protected $signature = 'order:cache:update {id?}';
    protected $description = 'Update order cache';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
      if(!$this->argument('id')) {
        $this->updateAll();
        return;
      } 

      $id = $this->argument('id');
      $order = AppOrder::find($id);

      $order->update($id);
    }

    private function updateAll() {

      (new Order)->flush();

      $orders = AppOrder::query()
      ->with(
        'progress:id,name',
        'captain:id,phone_number,user_id',
        'captain.user:id,name',
        'openCaptainTicket',
        'shop:id,name,auto_assignable,zone_id',
        'shop.zone:id,name',
        'shop.region:regions.id,regions.name',
      )
      ->with([
        'openCaptainTicket' => function($query) { 
            $query->withCount('notUserSeenMessages');
        }, 
        'openComplaint' => function($query) { 
            $query->withCount('notUserSeenMessages'); 
        }
    ])
      ->withRegionZone()
      ->withClient()
      ->whereNotIn('status_id', OrderStatus::FINISHED)
      ->get();

      foreach($orders as $order) {

        [$delivery_start_at, $delivery_finish_at] = $order->remainingTime();

        $data = [
          "id" => $order->id,
          "client_order_id" => $order->client_order_id,
          "client_id" => $order->client_id,
          "client_name" => $order->client_name,
          "shop_id" => $order->shop->id,
          "shop_name" => $order->shop->name,
          "area_id" => $order->shop->zone->id ?? null,
          "area" => $order->shop->zone->name ?? null,
          "zone_id" => $order->shop->region->id ?? null,
          "zone" => $order->shop->region->name ?? null,
          "amount" => $order->amount,
          "delivery_type" => $order->delivery_type,
          "delivery_date" => $order->delivery_date->timestamp,
          "delivery_start_at" => $delivery_start_at->timestamp,
          "delivery_finish_at" => $delivery_finish_at->timestamp,
          "status_id" => $order->status_id,
          "auto_assignable" => $order->shop->auto_assignable? "true" : "false",
          "has_open_complaint" => $order->openComplaint ? "true" : "false",
          "captain_id" => $order->captain_id ?? null,
          "created_at" => $order->created_at->timestamp,
          "progress" => [
            "id" => $order->progress->id,
            "status" => $order->progress->name,
            "class" => $order->progress->status_class,
          ]
        ];

        if(
          in_array($order->status_id, [OrderStatus::NEW_ORDER, OrderStatus::ORDER_PACKAGE, OrderStatus::ASSIGN_ATTEMPTS]) && 
          $order->shop->auto_assignable == 1
      ) {
          $dispatch_after = ($order->package && $order->package->package) ? $order->package->package->dispatch_after : null;
          $data['dispatch_after'] = $dispatch_after ? $dispatch_after->timestamp : null;
      }

        if($order->captain_id) {
          $data['captain'] = [
            "id" => $order->captain_id,
            "name" => $order->captain->user->name,
            "phone" => $order->captain->phone_number
          ];
        }

        if($order->openCaptainTicket) {
          $data['open_captain_ticket'] = [
            "id" => $order->openCaptainTicket->id,
            "unread_messages" => $order->openCaptainTicket->not_user_seen_messages_count
          ];
        }

        if($order->openComplaint) {
          $data['open_complaint'] = [
            "id" => $order->openComplaint->id,
            "unread_messages" => $order->openComplaint->not_user_seen_messages_count
          ];
        }

        try {
          (new Order)->set($order->id, $data, $data['created_at']);
        } catch (\Throwable $th) {
          $this->error($th->getMessage());
          $this->error("data: " . json_encode($data));
          $this->error("Error updating order $order->id");
        }
      }
    }
}