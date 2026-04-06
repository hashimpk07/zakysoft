<?php

namespace App\Services\General\Reports\KPIReports;

use App\Interfaces\General\Reports\CaptainKPIReportInterface;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

final class CaptainKPIReportService
{
   
    public function __construct(protected readonly CaptainKPIReportInterface $interface) {}

    public function getCaptainWorkingDaysReport($request, $perPage)
    {
        $from = Carbon::parse($request->get('from_date', now()->subDays(6)));
        $to = Carbon::parse($request->get('to_date', now()->subDay()));

        $filters = $request->all();
        $filters['from'] = $from->format('Y-m-d');
        $filters['to'] = $to->format('Y-m-d');

        $captains = $this->interface->getCaptains($filters,$perPage);

        $workingDays = $this->interface->getWorkingDays(
            $filters,
            $captains->pluck('id')->toArray()
        );

        $period = CarbonPeriod::create($from,$to);

        $reports = [];

        foreach ($captains as $captain) {

            $days = $workingDays->where('captain_id',$captain->id);

            $reportDays = [];

            foreach ($period as $date) {

                $workingDay = $days->firstWhere('date',$date->format('Y-m-d'));

                $reportDays[] = [
                    'date'=>$date->format('Y-m-d'),
                    'working_hr'=>$workingDay
                        ? gmdate("H:i:s",$workingDay->working_seconds)
                        : null,
                    'completed_orders'=>$workingDay->completed_orders ?? 0
                ];
            }

            $reports[] = [
                'captain'=>$captain,
                'days'=>$reportDays
            ];
        }

        return [
            'captains'=>$captains,
            'reports'=>$reports,
            'period'=>$period
        ];
    }

    public function getCaptainPerformanceReport($request, $perPage)  
    {
        $from = Carbon::parse(
            $request->get('from_date',now()->subDays(6))
        )->format('Y-m-d');

        $to = Carbon::parse(
            $request->get('to_date',now())
        )->format('Y-m-d');

        $filters = $request->all();
        $filters['from'] = $from;
        $filters['to'] = $to;

        return $this->interface->getCaptainPerformanceReport($filters, $perPage);
    }
}