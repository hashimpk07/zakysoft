<?php

namespace App\Console\Commands;

use App\Jobs\BoundPackage;
use App\Order;
use Illuminate\Console\Command;

class MakePackages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:make {--delivery_type='. Order::DELIVERY_TYPE_FAST .'}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create packages for orders that are ready to be delivered';


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
        $delivery_type =  $this->option('delivery_type', Order::DELIVERY_TYPE_FAST);

        BoundPackage::dispatch($delivery_type);
        
        if ($delivery_type == Order::DELIVERY_TYPE_FAST) {
            BoundPackage::dispatch(Order::DELIVERY_TYPE_FAST)->delay(now()->addSeconds(20));
            BoundPackage::dispatch(Order::DELIVERY_TYPE_FAST)->delay(now()->addSeconds(40));
        }

        return 0;  
    }
}