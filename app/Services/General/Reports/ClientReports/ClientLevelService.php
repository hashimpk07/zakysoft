<?php

namespace App\Services\General\Reports\ClientReports;

use App\Interfaces\General\ClientReports\ClientLevelInterface;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;


final class ClientLevelService
{
   
    public function __construct(protected readonly ClientLevelInterface $interface) {}

    public function getClients()
    {
        return $this->interface->getClients(true, true);
    }

    public function getClientShops()
    {
        return $this->interface->getClientShops(true);
    }

    public function getReports(array $filters, int $perPage = 100, int $subDays = 6)
    {
        [$from, $to] = $this->generateDateTime($subDays);

        $reports = $this->interface->getLevelReport([
            $from,
            $to,
            $filters['client_id'],
            $filters['shop_id']
        ], $perPage);

        $reports->getCollection()->transform(function ($item) {

            $item->avg_arrival = $item->avg_arrival_sec ? gmdate('H:i:s', $item->avg_arrival_sec) : '00:00:00';
            $item->avg_waiting = $item->avg_waiting_sec ? gmdate('H:i:s', $item->avg_waiting_sec) : '00:00:00';
            $item->avg_pickup_to_delivery = $item->avg_pickup_to_delivery_sec ? gmdate('H:i:s', $item->avg_pickup_to_delivery_sec) : '00:00:00';
            $item->avg_tct = $item->avg_tct_sec ? gmdate('H:i:s', $item->avg_tct_sec) : '00:00:00';

            $item->avg_distance = $item->avg_distance !== null
                ? round(max(0, $item->avg_distance), 2) . ' KM'
                : '0 KM';

            $item->success_rate = $item->received_orders
                ? round(($item->delivered_orders / $item->received_orders) * 100, 2) . '%'
                : '0%';

            $item->date = Carbon::parse($item->report_date)->format('d-m-Y');

            return $item;
        });

        return $reports;
    }

    private function generateDateTime(int $subDays): array
    {
        $fromDateRaw = request('from_date', now()->subDays($subDays)->format('Y-m-d'));
        $toDateRaw = request('to_date', now()->format('Y-m-d'));

        $fromTime = request('order_time_from', '06:00 AM');
        $toTime = request('order_time_to', '05:59 AM');

        $fromDateTime = Carbon::parse("$fromDateRaw $fromTime");
        $toDateTime = Carbon::parse("$toDateRaw $toTime");

        if (Carbon::parse($toTime)->lt(Carbon::parse($fromTime))) {
            $toDateTime->addDay();
        }

        return [$fromDateTime, $toDateTime];
    }

}