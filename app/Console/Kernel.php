<?php
namespace App\Console;

use App\Order;
use App\SystemSetting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Log;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        'App\Console\Commands\PrestashopOrder',
        \App\Console\Commands\CleanExpiredOtps::class,
        \App\Console\Commands\FireOrderAddressChangedCommand::class,
        \App\Console\Commands\CreateBulkTestOrders::class,
        \App\Console\Commands\UpdateBulkTestOrderStatuses::class,
        \App\Console\Commands\MapboxCacheCommand::class,
        \App\Console\Commands\NotifyShiftStart::class,
        \App\Console\Commands\NotifyCaptainToStartRide::class,
        \App\Console\Commands\NotifyOfflineCaptains::class,
        \App\Console\Commands\SyncCaptainWorkLogCommand::class
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();
        set_time_limit(900);
        ini_set('memory_limit', '-1');
        //$cron_log = storage_path() . "/logs/cron.log";

        // $schedule->command('cronjob:PrestashopOrderStore')
        //     ->everyMinute()->withoutOverlapping(10);
        // $schedule->command('monitor:captain')->everyMinute()->withoutOverlapping(10);
        $schedule->command('monitor:captain-store')->everyMinute()->withoutOverlapping(10);
        // $schedule->command('monitor:order-store')->everyMinute()->withoutOverlapping(10);
        $schedule->command('order:check-waiting-for-accept')->everyMinute();
        // $schedule->command('package:make')->everyMinute()->withoutOverlapping(10);
        $schedule->command('package:make')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->when(function () {
                // Get the system setting value for queue_status
                $systemSetting = SystemSetting::first();
                return $systemSetting && $systemSetting->queue_status;
            });
        // $schedule->command('package:make --delivery_type=' . Order::DELIVERY_TYPE_SCHEDULE)
        //     ->everyFifteenMinutes();
        $schedule->command('package:make --delivery_type=' . Order::DELIVERY_TYPE_SCHEDULE)
            ->everyFifteenMinutes()
            ->when(function () {
                // Get the system setting value for queue_status
                $systemSetting = SystemSetting::first();
                return $systemSetting && $systemSetting->queue_status;
            });
        // $schedule->command('package:send')->everyMinute()->withoutOverlapping(10);
        $schedule->command('package:send')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->when(function () {
                // Get the system setting value for queue_status
                $systemSetting = SystemSetting::first();
                return $systemSetting && $systemSetting->queue_status;
            });

        $schedule->command('notification:store')->dailyAt('00:00');
        $schedule->command('captain:rent')->dailyAt('23:00');

        // Send shift start notifications every minute
        // $schedule->command('captain:shift-notify')->everyMinute()->withoutOverlapping(10);


        $schedule->command('captain:clear-location')->dailyAt('06:01');

        // Only run in sandbox/testing environment
        if (app()->environment('sandbox', 'testing')) {
            // Create bulk test orders every day at 1:00 AM
            // Make sure to set the client_id and shop_id values in your .env file
            $schedule
                ->command('orders:create-bulk --client_id=' . env('TEST_CLIENT_ID') . ' --shop_id=' . env('TEST_SHOP_ID') . ' --count=1000')
                // ->dailyAt('01:00')
                ->hourly()
                ->withoutOverlapping(30);

            // Update order statuses in sequence every 15 minutes
            // This will take test orders through their lifecycle
            $schedule->command('orders:update-statuses --count=100')->everyFifteenMinutes()->withoutOverlapping(10);

            // Special hourly cycle for a smaller batch of orders
            // This ensures we have orders in different stages at all times
            $schedule
                ->command('orders:update-statuses --count=25 --status=' . env('TEST_ORDER_STATUS', '3'))
                ->hourly()
                ->withoutOverlapping(5);
        }
        $schedule->command('sendable:send')->everyMinute();

        $schedule->command('send:pending-order-reminder')->everyFiveMinutes();

        $schedule->command('close:captain-shift')->everyFiveMinutes();
        $schedule->command('send:captain-shift-reminder')->everyTenMinutes();

        $schedule->command('close:return-to-client-orders')->everyTenMinutes();
        $schedule->command('fast-close:return-to-client-orders')->everyMinute();

        $schedule->command('send:order-pickup-reminder')->everyFiveMinutes();
        $schedule->command('send:order-start-ride-reminder')->everyFiveMinutes();
        $schedule->command('send:order-complete-delivery-reminder')->everyFiveMinutes();

        $schedule->command('captain:idle-notify')->everyFiveMinutes();
        $schedule->command('captain:shift-notify')->everyMinute();
        $schedule->command('captain:offline-notify')->everyThreeMinutes();
        $schedule->command('captain:terminate-idle')->everyMinute();

        $schedule->command('report:daily-order-performance')->dailyAt('08:00');

        $schedule->command('export:clear-data')->dailyAt('06:01');

        $schedule->command('app:end-shifts')->dailyAt('06:00');

        $schedule->command('captain:reset-priorities')->dailyAt('06:00');

        $schedule->command('update:captain-work-log')->dailyAt('06:01');
        
        $schedule->command('captain:sync-work-log --period=daily')->dailyAt('08:00')->appendOutputTo(storage_path('logs/sync_captain_worklog.log'));
        $schedule->command('captain:sync-work-log --period=weekly')->weeklyOn(0, '08:00')->appendOutputTo(storage_path('logs/sync_captain_worklog.log'));
        $schedule->command('captain:sync-work-log --period=monthly')->monthlyOn(1, '08:00')->appendOutputTo(storage_path('logs/sync_captain_worklog.log'));
        
        $schedule->command('otp:cleanup')->everyMinute();
        $schedule->command('commission:calculate-daily')->dailyAt('06:10')->appendOutputTo(storage_path('logs/commission.log'));
        $schedule->command('app:auto-enable-dispatch-rules')->everyMinute();
        $schedule->command('app:enable-manual-assign-command')->everyMinute();

        $schedule->command('commission:special-condition-calculate-daily')->dailyAt('06:10');

        $schedule->command('captain:upgrade-from-low')->everyTenMinutes();

        $schedule->command('captain:consolidated-commission-report')->dailyAt('06:20');

        $schedule->command('captain:active-low-performance')->everyFifteenMinutes();

        $schedule->command('operation:check-activity')->everyMinute()->withoutOverlapping(10);

        $schedule->command('force-break-operation')->everyMinute()->withoutOverlapping(10);

        $schedule->command('force-close-operation-shift')->dailyAt('05:59');

        $schedule->command('captain:shift-rule-report')->dailyAt('06:00');

        $schedule->command('dispatch-rules:process-status')->everyMinute();

        $schedule->command('api-usage:cleanup')->dailyAt('04:00');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
