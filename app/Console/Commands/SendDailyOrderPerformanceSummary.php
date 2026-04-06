<?php

namespace App\Console\Commands;

use App\Mail\DailyOrderPerformanceSummaryMail;
use App\OrderReport;
use App\OrderStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendDailyOrderPerformanceSummary extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'report:daily-order-performance';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sends the daily order performance summary report to configured emails';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Calculate the previous business day 06:00 to 05:59
        // Example: If today is 26th Feb 06:00, we pull 25th 06:00 to 26th 05:59.
        $startDateTime = now()->subDay()->format('Y-m-d') . ' 06:00:00';
        $endDateTime = now()->format('Y-m-d') . ' 05:59:59';
        $testClientId = config('app.test_client_id');

        $baseQuery = OrderReport::query()
            ->finishedOrders()
            ->when($testClientId, function ($query, $testClientId) {
                return $query->where('order_reports.client_id', '!=', $testClientId);
            })
            ->leftJoin('clients', 'order_reports.client_id', '=', 'clients.id')
            ->leftJoin('users as client_user', 'clients.user_id', '=', 'client_user.id')
            ->whereBetween('order_reports.final_status_at', [$startDateTime, $endDateTime]);

        $totals = (clone $baseQuery)
            ->select(
                DB::raw('COUNT(order_reports.id) as total_orders'),
                DB::raw('SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN 1 ELSE 0 END) as delivered_orders'),
                DB::raw('SUM(CASE WHEN order_reports.status_id != ' . OrderStatus::DELIVERED . ' THEN 1 ELSE 0 END) as failed_orders'),
                DB::raw('SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN TIMESTAMPDIFF(MINUTE, order_reports.order_accepted_at, order_reports.reached_shop_at) ELSE 0 END) / NULLIF(SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN 1 ELSE 0 END), 0) as avg_arrival_time'),
                DB::raw('SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN TIMESTAMPDIFF(MINUTE, order_reports.reached_shop_at, order_reports.order_picked_at) ELSE 0 END) / NULLIF(SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN 1 ELSE 0 END), 0) as avg_waiting_time'),
                DB::raw('SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN TIMESTAMPDIFF(MINUTE, order_reports.order_picked_at, order_reports.final_status_at) ELSE 0 END) / NULLIF(SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN 1 ELSE 0 END), 0) as avg_pickup_to_delivery'),
                DB::raw('SUM(
                    CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN TIMESTAMPDIFF(MINUTE, order_reports.order_accepted_at, order_reports.reached_shop_at) ELSE 0 END +
                    CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN TIMESTAMPDIFF(MINUTE, order_reports.reached_shop_at, order_reports.order_picked_at) ELSE 0 END +
                    CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN TIMESTAMPDIFF(MINUTE, order_reports.order_picked_at, order_reports.final_status_at) ELSE 0 END
                ) / NULLIF(SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN 1 ELSE 0 END), 0) as avg_total_cycle'),
                DB::raw('SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN order_reports.shop_to_delivery_km ELSE 0 END) / NULLIF(SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN 1 ELSE 0 END), 0) as avg_distance')
            )
            ->first();

        $reports = (clone $baseQuery)
            ->select(
                'order_reports.shop_id',
                'order_reports.client_id',
                DB::raw('COUNT(order_reports.id) as total_orders'),
                DB::raw('SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN 1 ELSE 0 END) as delivered_orders'),
                DB::raw('SUM(CASE WHEN order_reports.status_id != ' . OrderStatus::DELIVERED . ' THEN 1 ELSE 0 END) as failed_orders'),
                DB::raw('SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN TIMESTAMPDIFF(MINUTE, order_reports.order_accepted_at, order_reports.reached_shop_at) ELSE 0 END) / NULLIF(SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN 1 ELSE 0 END), 0) as avg_arrival_time'),
                DB::raw('SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN TIMESTAMPDIFF(MINUTE, order_reports.reached_shop_at, order_reports.order_picked_at) ELSE 0 END) / NULLIF(SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN 1 ELSE 0 END), 0) as avg_waiting_time'),
                DB::raw('SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN TIMESTAMPDIFF(MINUTE, order_reports.order_picked_at, order_reports.final_status_at) ELSE 0 END) / NULLIF(SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN 1 ELSE 0 END), 0) as avg_pickup_to_delivery'),
                DB::raw('SUM(
                    CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN TIMESTAMPDIFF(MINUTE, order_reports.order_accepted_at, order_reports.reached_shop_at) ELSE 0 END +
                    CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN TIMESTAMPDIFF(MINUTE, order_reports.reached_shop_at, order_reports.order_picked_at) ELSE 0 END +
                    CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN TIMESTAMPDIFF(MINUTE, order_reports.order_picked_at, order_reports.final_status_at) ELSE 0 END
                ) / NULLIF(SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN 1 ELSE 0 END), 0) as avg_total_cycle'),
                DB::raw('SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN order_reports.shop_to_delivery_km ELSE 0 END) / NULLIF(SUM(CASE WHEN order_reports.status_id = ' . OrderStatus::DELIVERED . ' THEN 1 ELSE 0 END), 0) as avg_distance')
            )
            ->with([
                'client:id,user_id',
                'client.user:id,name',
                'shop:id,name'
            ])
            ->groupBy('order_reports.shop_id', 'order_reports.client_id', 'client_user.name')
            ->orderBy('client_user.name', 'asc')
            ->orderBy('order_reports.shop_id', 'asc')
            ->get();

        // Emails to send report to. Please update the below email as requirement
        $configuredEmails = config('app.order_performance_summary_emails');
        $emails = $configuredEmails ? explode(',', $configuredEmails) : []; 

        $csvContent = $this->generateCsv($reports, $totals);

        if(!empty($emails)) {
             Mail::to($emails)->send(new DailyOrderPerformanceSummaryMail($startDateTime, $endDateTime, $csvContent));
             $this->info("Successfully sent Daily Order Performance Summary to recipient(s).");
        } else {
             $this->error("No emails configured in app.order_performance_summary_emails. Daily Order Performance Summary cancelled.");
        }
    }

    private function generateCsv($reports, $totals)
    {
        $file = fopen('php://temp', 'w+');
        
        // Header
        fputcsv($file, [
            'Shop Name', 'Client Name', 'Total Orders', 'Delivered Orders', 'Failed Orders',
            'Average Arrival Time', 'Average Waiting Time', 'Average Pickup to Delivery Time',
            'Average Total Cycle Time', 'Average Distance (KM)'
        ]);
        
        // Data
        foreach ($reports as $report) {
            fputcsv($file, [
                $report->shop->name ?? 'N/A',
                $report->client->user->name ?? 'N/A',
                $report->total_orders,
                $report->delivered_orders,
                $report->failed_orders,
                $this->formatMinutesToTime($report->avg_arrival_time),
                $this->formatMinutesToTime($report->avg_waiting_time),
                $this->formatMinutesToTime($report->avg_pickup_to_delivery),
                $this->formatMinutesToTime($report->avg_total_cycle),
                number_format((float)$report->avg_distance, 2)
            ]);
        }
        
        // Totals
        if ($totals) {
            fputcsv($file, [
                'Grand Total/Average:',
                '',
                $totals->total_orders,
                $totals->delivered_orders,
                $totals->failed_orders,
                $this->formatMinutesToTime($totals->avg_arrival_time),
                $this->formatMinutesToTime($totals->avg_waiting_time),
                $this->formatMinutesToTime($totals->avg_pickup_to_delivery),
                $this->formatMinutesToTime($totals->avg_total_cycle),
                number_format((float)$totals->avg_distance, 2)
            ]);
        }
        
        rewind($file);
        $csvContent = stream_get_contents($file);
        fclose($file);
        
        return $csvContent;
    }

    private function formatMinutesToTime($minutes)
    {
        if (is_null($minutes)) return 'N/A';
        $totalSeconds = intval($minutes * 60);
        return secondsToTime($totalSeconds);
    }
}
