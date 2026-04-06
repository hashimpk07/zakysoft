<?php

namespace App\Services\Reports;

use App\Interfaces\ClientReportInterface;
use App\Interfaces\ListInterface;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;

class ClientLevelService
{
    public function __construct(protected readonly ListInterface $listInterface, protected readonly ClientReportInterface $clientReportInterface) {}

    public function getClients(array $filters = [])
    {
        $isActive = $filters['active'] ?? false;
        $withName = $filters['withName'] ?? false;
        return $this->listInterface->getClients(isActive: $isActive, withName: $withName);
    }

    public function getClientShops(array $filters = [])
    {
        $active = $filters['active'] ?? false;
        return $this->listInterface->getClientShops(
            filters: [
                'active' => $active,
            ],
        );
    }

    public function getReports(array $filters = [], int $perPage = 100, int $subDays = 6): LengthAwarePaginator
    {
        [$from, $to] = $this->generateDateTime(subDays: $subDays); // from date and to date will fetch automatically from the request
        $reports = $this->clientReportInterface->getLevelReport(filters: [$from, $to, $filters['client_id'], $filters['shop_id']], perPage: $perPage);

        // Transform numeric seconds into HH:MM / HH:MM:SS
        $reports->getCollection()->transform(function ($item) {
            $item->avg_arrival = $item->avg_arrival_sec ? gmdate('H:i:s', $item->avg_arrival_sec) : '00:00:00';
            $item->avg_waiting = $item->avg_waiting_sec ? gmdate('H:i:s', $item->avg_waiting_sec) : '00:00:00';
            $item->avg_pickup_to_delivery = $item->avg_pickup_to_delivery_sec ? gmdate('H:i:s', $item->avg_pickup_to_delivery_sec) : '00:00:00';
            $item->avg_tct = $item->avg_tct_sec ? gmdate('H:i:s', $item->avg_tct_sec) : '00:00:00';
            $item->avg_distance = $item->avg_distance !== null ? round(max(0, $item->avg_distance), 2) . ' KM' : '0 KM';
            $item->success_rate = $item->received_orders ? round(($item->delivered_orders / $item->received_orders) * 100, 2) . '%' : '0%';
            $item->date = Carbon::parse($item->report_date)->format('d-m-Y');
            return $item;
        });

        return $reports;
    }

    private function generateDateTime(int $subDays = 6): array
    {
        $fromDateRaw = request('from_date', now()->subDays($subDays)->format('Y-m-d'));
        $toDateRaw = request('to_date', now()->format('Y-m-d'));

        $fromTime = request('order_time_from', '06:00 AM');
        $toTime = request('order_time_to', '05:59 AM');

        $fromDateTime = Carbon::parse("$fromDateRaw $fromTime");
        $toDateTime = Carbon::parse("$toDateRaw $toTime");

        // Handle overnight time window
        if (Carbon::parse($toTime)->lt(Carbon::parse($fromTime))) {
            $toDateTime->addDay();
        }

        return [$fromDateTime, $toDateTime];
    }
}
