<?php

namespace App\Console\Commands;

use App\Captain;
use App\CaptainStore;
use App\ClientShop;
use App\Order;
use App\OrderStore;
use App\Services\Map;
use App\Services\Position;
use Illuminate\Console\Command;

class OrderStoreDistanceLog extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:order-store';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find distance between Order and store';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $new_orders = Order::with('shop')
                ->doesntHave('storeDistance')
                ->readyToDispatch()
                ->autoAssignable()
                ->get();

        foreach ($new_orders as $key => $new_order) {
            $client_shop = $new_order->shop;
            $this->logLocation($client_shop, $new_order);
        }
    }

    public function logLocation($shop, $order)
    {
        if(!$order->location) {
            return ;
        }
        $storePosition = new Position(...array_reverse(explode(',', $shop->location)));
        $order_location = new Position(...array_reverse(explode(',', $order->location)));
        $direction = (new Map)->AirDirection($order_location, $storePosition);

        OrderStore::updateOrCreate([
            'order_id' => $order->id,
            'client_shop_id' => $shop->id
        ], [
            'distance' => $direction->distance(),
            'traveling_time' => $direction->duration()
        ]);
    }
}
