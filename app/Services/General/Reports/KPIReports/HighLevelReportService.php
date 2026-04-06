<?php

namespace App\Services\General\Reports\KPIReports;

use App\Interfaces\General\Reports\HighLevelReportInterface;
use Carbon\Carbon;

final class HighLevelReportService
{
   
    public function __construct(protected readonly HighLevelReportInterface $interface) {}

    public function getHighLevelReport($request, $perPage)
    {
        $fromTime = $request->order_time_from ?? '06:00:00';
        $toTime = $request->order_time_to ?? '05:59:59';

        $fromDate = $request->from_date
            ? Carbon::parse($request->from_date.' '.$fromTime)
            : now()->subDays(6)->setTime(6,0);

        $toDate = $request->to_date
            ? Carbon::parse($request->to_date.' '.$toTime)->addDay()
            : now()->addDay()->setTime(5,59);

        $filters = $request->all();
        $filters['fromDate'] = $fromDate;
        $filters['toDate'] = $toDate;

        return $this->interface->getHighLevelReport($filters,$perPage);
 
    }


}