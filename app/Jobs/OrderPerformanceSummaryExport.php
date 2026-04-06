<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\OrderReport;
use App\OrderStatus;
use App\User;
use Illuminate\Support\Facades\DB;

class OrderPerformanceSummaryExport extends QueueExport
{
    protected int $chunk = 50000;

    protected string $file_name = 'order-performance-summary';

    public function data(): array
    {
        $data = [];
        $reports = $this->getOrders();

        foreach ($reports as $report) {
            $data[] = [
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
            ];
        }

        return $data;
    }

    public function headers(): array
    {
        return [
            'Shop Name',
            'Client Name',
            'Total Orders',
            'Delivered Orders',
            'Failed Orders',
            'Average Arrival Time',
            'Average Waiting Time',
            'Average Pickup to Delivery Time',
            'Average Total Cycle Time',
            'Average Distance (KM)'
        ];
    }

    public function getOrders()
    {
        $request = $this->export->filters;

        $client = $request['client'] ?? null;
        $shop = $request['shop'] ?? null;
        
        $fromDate = $request['from_date'] ?? now()->subDays(6)->format('Y-m-d');
        $toDate = $request['to_date'] ?? now()->format('Y-m-d');
        $orderTimeFrom = $request['order_time_from'] ?? '06:00:00';
        $orderTimeTo = $request['order_time_to'] ?? '05:59:59';

        $endDateTime = now()->parse($toDate . ' ' . $orderTimeTo)->addDay()->format('Y-m-d H:i:s');
        $startDateTime = now()->parse($fromDate . ' ' . $orderTimeFrom)->format('Y-m-d H:i:s');

        $reports = OrderReport::query()
            ->select(
                'order_reports.shop_id',
                'order_reports.client_id',
                'client_user.name as expected_client_name',
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
            ->leftJoin('clients', 'order_reports.client_id', '=', 'clients.id')
            ->leftJoin('users as client_user', 'clients.user_id', '=', 'client_user.id')
            ->belongsToUser(User::find($this->export->created_by))
            ->finishedOrders()
            ->when(config('app.test_client_id'), function ($query, $testClientId) {
                return $query->where('order_reports.client_id', '!=', $testClientId);
            })
            ->whereBetween('order_reports.final_status_at', [$startDateTime, $endDateTime])
            ->when($client, function ($query, $client) {
                return $query->where('order_reports.client_id', $client);
            })
            ->when($shop, function ($query, $shop) {
                return $query->where('order_reports.shop_id', $shop);
            })
            ->groupBy('order_reports.shop_id', 'order_reports.client_id', 'client_user.name')
            ->orderBy('client_user.name', 'asc')
            ->orderBy('order_reports.shop_id', 'asc')
            ->limit($this->chunk)
            ->offset($this->chunk * ($this->export->page_done ?? 0))
            ->get();

        return $reports;
    }

    public function count(): int
    {
        $request = $this->export->filters;

        $client = $request['client'] ?? null;
        $shop = $request['shop'] ?? null;
        
        $fromDate = $request['from_date'] ?? now()->subDays(6)->format('Y-m-d');
        $toDate = $request['to_date'] ?? now()->format('Y-m-d');
        $orderTimeFrom = $request['order_time_from'] ?? '06:00:00';
        $orderTimeTo = $request['order_time_to'] ?? '05:59:59';

        $endDateTime = now()->parse($toDate . ' ' . $orderTimeTo)->addDay()->format('Y-m-d H:i:s');
        $startDateTime = now()->parse($fromDate . ' ' . $orderTimeFrom)->format('Y-m-d H:i:s');

        $subQuery = OrderReport::query()
            ->select('order_reports.shop_id', 'order_reports.client_id', 'client_user.name')
            ->leftJoin('clients', 'order_reports.client_id', '=', 'clients.id')
            ->leftJoin('users as client_user', 'clients.user_id', '=', 'client_user.id')
            ->belongsToUser(User::find($this->export->created_by))
            ->finishedOrders()
            ->when(config('app.test_client_id'), function ($q, $testCId) { $q->where('order_reports.client_id', '!=', $testCId); })
            ->whereBetween('order_reports.final_status_at', [$startDateTime, $endDateTime])
            ->when($client, function ($q, $c) { $q->where('order_reports.client_id', $c); })
            ->when($shop, function ($q, $s) { $q->where('order_reports.shop_id', $s); })
            ->groupBy('order_reports.shop_id', 'order_reports.client_id', 'client_user.name');

        return DB::query()
            ->fromSub($subQuery, 'sub')
            ->count() ?? 0;
    }

    private function formatMinutesToTime($minutes)
    {
        if (is_null($minutes)) return 'N/A';
        $totalSeconds = intval($minutes * 60);
        return secondsToTime($totalSeconds);
    }
}
