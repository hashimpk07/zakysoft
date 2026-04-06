<?php

namespace App\Services\General\Reports\KPIReports;

use App\Interfaces\General\Reports\DriverLevelInterface;
use Carbon\Carbon;

final class DriverLevelReportService
{
   
    public function __construct(protected readonly DriverLevelInterface $interface) {}

    public function getDriverLevelReport($request, $perPage)
    {
        $from_date = $request->has('from_date')
            ? Carbon::parse($request->get('from_date'))->startOfDay()
            : now()->subDays(6)->startOfDay();

        $to_date = $request->has('to_date')
            ? Carbon::parse($request->get('to_date'))->endOfDay()
            : now()->subDay()->endOfDay();

        $filters = $request->all();
        $filters['from_date'] = $from_date;
        $filters['to_date'] = $to_date;

        $reports = $this->interface->getDriverLevelReport($filters,$perPage);

        $reports->getCollection()->transform(function ($item) {

            $item->online_hours = $this->formatSeconds($item->total_seconds_worked);

            $item->avg_online_hours = $this->formatSeconds(
                $item->working_days ? $item->total_seconds_worked / $item->working_days : 0
            );

            $item->acceptance_rate = $item->total_orders_received
                ? round(($item->total_orders_accepted / $item->total_orders_received) * 100, 2) . '%'
                : '0%';

            $item->success_rate = $item->total_orders_delivered
                ? round(($item->total_orders_delivered / $item->total_orders_accepted) * 100, 2) . '%'
                : '0%';

            return $item;
        });

        return $reports;
    }

    private function formatSeconds($seconds)
    {
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        $s = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
}