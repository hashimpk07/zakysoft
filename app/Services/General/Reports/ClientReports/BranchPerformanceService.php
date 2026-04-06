<?php

namespace App\Services\General\Reports\ClientReports;

use App\Interfaces\General\ClientReports\BranchPerformanceInterface;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;


final class BranchPerformanceService
{
   
    public function __construct(protected readonly BranchPerformanceInterface $interface) {}

    public function getBranchPerformanceReport($request)
    {
        $perPage = $request->input('per_page', 20);
        $fromDate = $request->get('from_date', now()->subDays(6)->format('Y-m-d'));
        $toDate = $request->get('to_date', now()->format('Y-m-d'));

        $orderTimeFrom = $request->get('order_time_from', '06:00:00');
        $orderTimeTo = $request->get('order_time_to', '05:59:59');

        $startDateTime = Carbon::parse($fromDate.' '.$orderTimeFrom);
        $endDateTime = Carbon::parse($toDate.' '.$orderTimeTo)->addDay();

        $filters = [
            'startDateTime' => $startDateTime,
            'endDateTime' => $endDateTime,
            'client' => $request->client,
            'shop' => $request->shop
        ];

        $baseQuery = $this->interface->baseQuery($filters);

        $totals = $this->interface->getTotals($baseQuery);

        $reports = $this->interface->getReports($baseQuery,$perPage);

        return [
            'totals' => $totals,
            'reports' => $reports
        ];
    }

}