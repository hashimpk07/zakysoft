<?php

namespace App\Console\Commands;

use DB;
use App\Order;
use App\OrderItem;
use App\User;
use App\Client;
use App\Http\Controllers\Controller;
use Log;
use Facades\App\Services\OrderStatusLog;
use Facades\App\Services\PrestashopOrders;
use Illuminate\Console\Command;
use Prestashop;

class PrestashopOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cronjob:PrestashopOrderStore';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'prestashop Order Store.';

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
     * @return mixed
     */
    public function handle()
    {

        try {
            // lets begin.
            $Controller = new Controller();
            $Controller->log_info('prestashop - Process started.');
            // Log::info('prestashop - Process started.');

            $order = PrestashopOrders::storeOrder();
            // Log::info('- prestashop  order Save to db.');

           
        } catch (Exception $e) {
            // rollback transaction!
            //DB::rollback();
            $Controller->log_info('Exception: ' . $e->getMessage() . ' @ ' . $e->getLine(), 1);
            // Log::info('Exception: ' . $e->getMessage() . ' @ ' . $e->getLine(), 1);
        }
        // Commit the queries!
        //DB::commit();
        // all good.
        // $Controller->log_info('prestashop: Order created sucessfully.');
        // Log::info('prestashop: Order created sucessfully.');
        // $Controller->log_info('=============================================================================');
        // Log::info('=============================================================================');
        exit;
    }

}
