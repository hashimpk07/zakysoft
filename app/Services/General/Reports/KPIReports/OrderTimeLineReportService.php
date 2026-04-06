<?php

namespace App\Services\General\Reports\KPIReports;

use App\Interfaces\General\Reports\OrderTimeLineReportInterface;
use Carbon\Carbon;

final class OrderTimeLineReportService
{
   
    public function __construct(protected readonly OrderTimeLineReportInterface $interface) {}

    public function getOrderTimeLineReport($request, $perPage)
    {
        $fromDate = $request->get('from_date', now()->subDays(6)->format('Y-m-d'));
        $toDate = $request->get('to_date', now()->format('Y-m-d'));

        $orderTimeFrom = $request->get('order_time_from', '06:00:00');
        $orderTimeTo = $request->get('order_time_to', '05:59:59');

        $startDateTime = Carbon::parse($fromDate . ' ' . $orderTimeFrom)->format('Y-m-d H:i:s');
        $endDateTime = Carbon::parse($toDate . ' ' . $orderTimeTo)->addDay()->format('Y-m-d H:i:s');

        $filters = [
            'startDateTime' => $startDateTime,
            'endDateTime' => $endDateTime,
            'client_order_id' => $request->client_order_id,
            'client' => $request->client,
            'shop' => $request->shop
        ];

        return $this->interface->getOrderTimeLineReport($filters,$perPage);
 
    }
}