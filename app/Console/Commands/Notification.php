<?php

namespace App\Console\Commands;

use Facades\App\Services\NotificationService;
use Illuminate\Console\Command;

class Notification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */

    protected $signature = 'notification:store';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'To store notification on DB table';

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
        echo NotificationService::getExpiryDatas();
    }
}
