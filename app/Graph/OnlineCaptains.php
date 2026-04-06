<?php
namespace App\Graph;

use Illuminate\Support\Facades\DB;

class OnlineCaptains implements Graph
{
    public function data($request = null)
    {
        $toDate = $request->get('to_date', now()->format('Y-m-d'));

        // Business day: from 6:00 AM on toDate to 5:59:59 AM next day
        $toDateTime = \Carbon\Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);
        $day = $toDateTime->format('Y-m-d H:i:s');

        $colors = [
            '#10b981', '#eab308', '#f97316', '#ef4444', '#84cc16', '#22c55e', '#10b981',
            '#14b8a6', '#06b6d4', '#0ea5e9', '#3b82f6', '#6366f1', '#8b5cf6', '#a855f7',
            '#d946ef', '#ec4899', '#f43f5e', '#1e40af', '#6b21a8', '#831843', '#9f1239',
            '#3b0764', '#9333ea', '#0c4a6e',
        ];

        // Generate business day hour labels: 6 AM to 5 AM
        $hours = array_merge(range(6, 23), range(0, 5));
        $labels = array_map(function ($hour) {
            $meridiem = $hour >= 12 ? 'PM' : 'AM';
            $displayHour = $hour % 12 === 0 ? 12 : $hour % 12;
            return sprintf('%02d:00 %s', $displayHour, $meridiem);
        }, $hours);

        $rawHourSQL = "hourly_intervals.hour";

        $data = DB::table('shift_statuses')
            ->select(
                DB::raw('COUNT(DISTINCT shift_statuses.captain_id) as count'),
                DB::raw($rawHourSQL . " as hour")
            )
            ->join('hourly_intervals', function ($join) use ($day) {
                $join->whereRaw("
                    hourly_intervals.hour >= (
                        CASE
                          WHEN DATEDIFF(shift_statuses.shift_start, '$day') = 0 THEN HOUR(shift_statuses.shift_start)
                          WHEN DATEDIFF(shift_statuses.shift_start, '$day') < 0 THEN 0
                        END
                    )
                    AND hourly_intervals.hour <= (
                        CASE
                          WHEN DATEDIFF('$day', shift_statuses.shift_end) = 0 THEN HOUR(shift_statuses.shift_end)
                          WHEN DATEDIFF('$day', shift_statuses.shift_end) < 0 THEN 23
                        END
                    )
                ");
            })
            ->whereRaw("
                (
                    shift_statuses.shift_start > '$day' OR
                    (shift_statuses.shift_start < '$day' AND (shift_statuses.shift_end > '$day' OR shift_statuses.shift_end IS NULL))
                )
            ")
            ->groupByRaw($rawHourSQL)
            ->orderByRaw($rawHourSQL)
            ->get()
            ->keyBy('hour');

        // Map data to values aligned with business hours
        $values = [];
        foreach ($hours as $hour) {
            $values[] = $data[$hour]->count ?? 0;
        }

        return compact('labels', 'values', 'colors');
    }

    public function data_old($request = null)
    {
        //$day = request()->has('to_date') ? now()->parse(request()->get('to_date'))->startOfDay() : now()->startOfDay();
        $toDate = $request->get('to_date', now()->format('Y-m-d'));
        $toDateTime =  \Carbon\Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        //This is for filtering the orders based on the date range
        $day =  $toDateTime->format('Y-m-d H:i:s');

        $colors = [
            '#10b981',
            '#eab308',
            '#f97316',
            '#ef4444',
            '#84cc16',
            '#22c55e',
            '#10b981',
            '#14b8a6',
            '#06b6d4',
            '#0ea5e9',
            '#3b82f6',
            '#6366f1',
            '#8b5cf6',
            '#a855f7',
            '#d946ef',
            '#ec4899',
            '#f43f5e',
            '#1e40af',
            '#6b21a8',
            '#831843',
            '#9f1239',
            '#3b0764',
            '#9333ea',
            '#0c4a6e',
        ];

        $labels = [
            "00:00 AM",
            "01:00 AM",
            "02:00 AM",
            "03:00 AM",
            "04:00 AM",
            "05:00 AM",
            "06:00 AM",
            "07:00 AM",
            "08:00 AM",
            "09:00 AM",
            "10:00 AM",
            "11:00 AM",
            "12:00 PM",
            "01:00 PM",
            "02:00 PM",
            "03:00 PM",
            "04:00 PM",
            "05:00 PM",
            "06:00 PM",
            "07:00 PM",
            "08:00 PM",
            "09:00 PM",
            "10:00 PM",
            "11:00 PM",
        ];

        $data = DB::table('shift_statuses')
            ->select(
                DB::raw('COUNT(DISTINCT shift_statuses.captain_id) as count'),
                'hourly_intervals.hour'
            )
            ->join('hourly_intervals', function($join) use ($day) {
                $join->whereRaw("
                    hourly_intervals.hour >= (
                        CASE
                          WHEN DATEDIFF(`shift_statuses`.`shift_start`, '$day') = 0 THEN hour(shift_statuses.shift_start)
                          WHEN DATEDIFF(`shift_statuses`.`shift_start`, '$day') < 0 THEN 0
                        END
                      )  AND hourly_intervals.hour <= (
                        CASE
                          WHEN DATEDIFF('$day', shift_statuses.shift_end) = 0 THEN hour(shift_statuses.shift_end)
                          WHEN DATEDIFF('$day', shift_statuses.shift_end) < 0 THEN 23
                        END
                      )
                ");
            })
            ->whereRaw("
            (
                `shift_statuses`.`shift_start` > '$day' OR
                (`shift_statuses`.`shift_start` < '$day' AND (`shift_statuses`.`shift_end` > '$day' OR `shift_statuses`.`shift_end` is null))
              )
            ")
            ->groupBy('hourly_intervals.hour')
            ->orderBy('hourly_intervals.hour')
            ->get();

        $values = $data->pluck('count');

        return compact('labels', 'values', 'colors');
    }
}
