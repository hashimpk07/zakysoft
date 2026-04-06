<?php
namespace App\Actions;

use App\Captain;
use App\CaptainWorkingLog;
use App\Order;
use App\OrderStatus;
use App\PackageDeliveryRequest;
use App\ShiftStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateCaptainWorkLog
{
    public function execute(Captain $captain, ?Carbon $date = null): void
    {
        try {
            if (!$date) {
                $date = now();
                if (Carbon::parse($date)->format('H:i:s') < '06:00:00') {
                    $date = Carbon::parse($date)->subDay()->format('Y-m-d');
                }
            }

            // Log::channel('discord')->info('UpdateCaptainWorkLog action called', [
            //     'captain_id' => $captain->id,
            //     'date' => $date->format('Y-m-d H:i:s')
            // ]);
            //else{
            //     if(Carbon::parse($date)->format('H:i:s') < '06:00:00' ){
            //         $date = Carbon::parse($date)->subDay()->format('Y-m-d');
            //     } 
            // }
            $date = Carbon::parse($date);

            $businessDayStart = $date->copy()->setTime(6, 0, 0)->format('Y-m-d H:i:s');
            $businessDayEnd = $date->copy()->addDay()->setTime(5, 59, 59)->format('Y-m-d H:i:s');
            $businessDayStart = Carbon::parse($businessDayStart);
            $businessDayEnd = Carbon::parse($businessDayEnd);

            //        Log::channel('discord')->info('Business Day', [
            //     'businessDayStart' => $businessDayStart,
            //     'businessDayEnd' => $businessDayEnd
            // ]);
            // dd($date,$businessDayStart, $businessDayEnd);
            if ($seconds_worked = $this->shiftSecondsWorked($captain, $date, $businessDayStart, $businessDayEnd)) {
                CaptainWorkingLog::updateOrCreate([
                    'captain_id' => $captain->id,
                    'date' => $date->format('Y-m-d'),
                ], [
                    'seconds_worked' => $seconds_worked,
                    'orders_received' => $this->noOfOrdersReceived($captain, $businessDayStart, $businessDayEnd),
                    'orders_try_to_accept' => $this->noOfOrderTryToAccept($captain, $businessDayStart, $businessDayEnd),
                    'orders_rejected' => $this->noOfOrdersRejected($captain, $businessDayStart, $businessDayEnd),
                    'orders_expired' => $this->onOfOrdersExpired($captain, $businessDayStart, $businessDayEnd),
                    'orders_accepted' => $this->onOfOrdersAccepted($captain, $businessDayStart, $businessDayEnd),
                    'orders_delivered' => $this->onOfOrdersDelivered($captain, $businessDayStart, $businessDayEnd),
                    'orders_returned' => $this->onOfOrdersReturned($captain, $businessDayStart, $businessDayEnd),
                    'orders_reassigned' => $this->noOfOrdersReassigned($captain, $businessDayStart, $businessDayEnd),
                    'orders_cancelled' => $this->onOfOrdersCancelled($captain, $businessDayStart, $businessDayEnd),
                ]);
            }
        } catch (\Exception $exception) {
            Log::channel('discord')->error('Error in UpdateCaptainWorkLog', [
                'captain_id' => $captain->id,
                'date' => isset($date) ? $date->format('Y-m-d H:i:s') : null,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);
        }
    }



    public function shiftSecondsWorked($captain, Carbon $date, ?Carbon $businessDayStart = null, ?Carbon $businessDayEnd = null): int
    {
        $businessDayStart = $businessDayStart ?? $date->copy()->setTime(6, 0, 0);
        $businessDayEnd = $businessDayEnd ?? $businessDayStart->copy()->addDay()->subSecond();

        $shifts = ShiftStatus::query()
            ->where('captain_id', $captain->id)
            ->where(function ($query) use ($businessDayStart, $businessDayEnd) {
                $query->whereBetween('shift_start', [$businessDayStart, $businessDayEnd])
                    ->orWhereBetween('shift_end', [$businessDayStart, $businessDayEnd])
                    ->orWhere(function ($q) use ($businessDayStart, $businessDayEnd) {
                        $q->where('shift_start', '<', $businessDayStart)
                            ->where('shift_end', '>', $businessDayEnd);
                    });
            })
            ->get()
            ->unique(fn($shift) => $shift->shift_start . '|' . $shift->shift_end)
            ->map(function ($shift) use ($businessDayStart, $businessDayEnd) {
                // Clip to business day
                $start = Carbon::parse($shift->shift_start)->greaterThan($businessDayStart)
                    ? Carbon::parse($shift->shift_start)
                    : $businessDayStart;

                $end = Carbon::parse($shift->shift_end)->lessThan($businessDayEnd)
                    ? Carbon::parse($shift->shift_end)
                    : $businessDayEnd;

                return ['start' => $start, 'end' => $end];
            })
            ->sortBy('start')
            ->values();

        // Merge overlapping intervals
        $merged = [];
        foreach ($shifts as $shift) {
            if (empty($merged)) {
                $merged[] = $shift;
            } else {
                $last = &$merged[count($merged) - 1];
                if ($shift['start']->lessThanOrEqualTo($last['end'])) {
                    // Overlaps or touches → merge by extending end time
                    if ($shift['end']->greaterThan($last['end'])) {
                        $last['end'] = $shift['end'];
                    }
                } else {
                    $merged[] = $shift;
                }
            }
        }

        // Sum merged intervals
        $totalSeconds = 0;
        foreach ($merged as $interval) {
            $totalSeconds += $interval['end']->diffInSeconds($interval['start']);
        }

        return $totalSeconds;
    }

    public function noOfOrdersReceived($captain, $businessDayStart, $businessDayEnd): int
    {

        //    return $total = PackageDeliveryRequest::query()
        // ->leftJoin('packages', 'packages.id', '=', 'package_delivery_requests.package_id')
        // ->leftJoin('package_orders', 'package_orders.package_id', '=', 'packages.id')
        // ->leftJoin('orders', 'orders.id', '=', 'package_orders.order_id')
        // ->where('package_delivery_requests.captain_id', $captain->id)
        // ->whereBetween('orders.delivery_date', [$businessDayStart, $businessDayEnd])
        // ->whereNotNull('package_orders.order_id')
        // ->select(DB::raw('COUNT(*) as cnt'))
        // ->groupBy('package_orders.order_id')
        // ->get()
        // ->sum('cnt');
        return PackageDeliveryRequest::query()
            ->leftJoin('packages', 'packages.id', '=', 'package_delivery_requests.package_id')
            ->leftJoin('package_orders', 'package_orders.package_id', '=', 'packages.id')
            ->leftJoin('orders', 'orders.id', '=', 'package_orders.order_id')
            ->where('package_delivery_requests.captain_id', $captain->id)
            ->whereBetween('orders.delivery_date', [$businessDayStart, $businessDayEnd])
            ->whereNotNull('order_id')
            ->groupBy('order_id')
            ->select(DB::raw('count(*) as count'))
            ->get()
            ->count();

        //   $results= PackageDeliveryRequest::query()
        //     ->leftJoin('packages', 'packages.id', '=', 'package_delivery_requests.package_id')
        //     ->leftJoin('package_orders', 'package_orders.package_id', '=', 'packages.id')
        //     ->leftJoin('orders', 'orders.id', '=', 'package_orders.order_id')
        //     ->where('package_delivery_requests.captain_id', $captain->id)
        //     ->whereBetween('orders.delivery_date', [$businessDayStart, $businessDayEnd])
        //     ->whereNotNull('package_delivery_requests.order_id')
        //     ->groupBy(['package_delivery_requests.order_id','packages.id'])
        //     ->select(DB::raw('count(*) as count'))
        //     ->get();
        //     // ->count();

        // return $results->sum('count');

        // Add this section
        // ->distinct()
        // ->count('package_orders.order_id');
        //Remove this section
        // ->groupBy('order_id')
        // ->select(DB::raw('count(*) as count'))
        // ->get()
        // ->count();
    }

    public function noOfOrderTryToAccept($captain, $businessDayStart, $businessDayEnd): int
    {
        return PackageDeliveryRequest::query()
            ->leftJoin('packages', 'packages.id', '=', 'package_delivery_requests.package_id')
            ->leftJoin('package_orders', 'package_orders.package_id', '=', 'packages.id')
            ->leftJoin('orders', 'orders.id', '=', 'package_orders.order_id')
            ->where('package_delivery_requests.captain_id', $captain->id)
            ->whereBetween('orders.delivery_date', [$businessDayStart, $businessDayEnd])
            ->whereNotNull('package_delivery_requests.attempted_at')
            ->where(function ($query) {
                $query
                    ->whereColumn('package_delivery_requests.captain_id', '<>', 'orders.captain_id')
                    ->orWhere('orders.captain_id', null);
            })
            ->whereColumn('packages.captain_id', '<>', 'package_delivery_requests.captain_id')
            ->whereNotNull('package_orders.order_id')
            ->whereRaw('package_delivery_requests.id = (select max(pdr.id) from package_delivery_requests as pdr where pdr.package_id = package_delivery_requests.package_id AND pdr.captain_id = ' . $captain->id . ' group by pdr.package_id limit 1)')
            ->groupBy('package_orders.order_id')
            ->select(DB::raw('count(*) as count'))
            ->get()
            ->count();
    }

    public function noOfOrdersRejected($captain, $businessDayStart, $businessDayEnd): int
    {
        return PackageDeliveryRequest::query()
            ->leftJoin('packages', 'packages.id', '=', 'package_delivery_requests.package_id')
            ->leftJoin('package_orders', 'package_orders.package_id', '=', 'packages.id')
            ->leftJoin('orders', 'orders.id', '=', 'package_orders.order_id')
            ->where('package_delivery_requests.captain_id', $captain->id)
            ->where(function ($query) {
                $query
                    ->whereColumn('package_delivery_requests.captain_id', '<>', 'orders.captain_id')
                    ->orWhere('orders.captain_id', null);
            })
            ->where(function ($query) {
                $query
                    ->whereColumn('package_delivery_requests.captain_id', '<>', 'packages.captain_id')
                    ->orWhere('packages.captain_id', null);
            })
            //->whereColumn('packages.captain_id', '<>', 'package_delivery_requests.captain_id')
            ->whereBetween('orders.delivery_date', [$businessDayStart, $businessDayEnd])
            ->whereNotNull('package_delivery_requests.declined_at')
            ->whereNotNull('package_orders.order_id')
            ->whereRaw('package_delivery_requests.id = (select max(pdr.id) from package_delivery_requests as pdr where pdr.package_id = package_delivery_requests.package_id AND pdr.captain_id = ' . $captain->id . ' group by pdr.package_id limit 1)')
            ->groupBy('package_orders.order_id')
            ->select(DB::raw('count(*) as count'))
            ->get()
            ->count();
    }

    public function onOfOrdersExpired($captain, $businessDayStart, $businessDayEnd)
    {
        return PackageDeliveryRequest::query()
            ->leftJoin('packages', 'packages.id', '=', 'package_delivery_requests.package_id')
            ->leftJoin('package_orders', 'package_orders.package_id', '=', 'packages.id')
            ->leftJoin('orders', 'orders.id', '=', 'package_orders.order_id')
            ->where('package_delivery_requests.captain_id', $captain->id)
            ->where(function ($query) {
                $query
                    ->whereColumn('package_delivery_requests.captain_id', '<>', 'orders.captain_id')
                    ->orWhere('orders.captain_id', null);
            })
            ->whereColumn('packages.captain_id', '<>', 'package_delivery_requests.captain_id')
            ->whereBetween('orders.delivery_date', [$businessDayStart, $businessDayEnd])
            ->whereNull('package_delivery_requests.declined_at')
            ->whereNull('package_delivery_requests.attempted_at')
            ->whereNotNull('package_orders.order_id')
            ->whereRaw('package_delivery_requests.id = (select max(pdr.id) from package_delivery_requests as pdr where pdr.package_id = package_delivery_requests.package_id AND pdr.captain_id = ' . $captain->id . ' group by pdr.package_id limit 1)')
            ->groupBy('package_orders.order_id')
            ->select(DB::raw('count(*) as count'))
            ->get()
            ->count();
    }

    // public function onOfOrdersAccepted($captain, $businessDayStart, $businessDayEnd): int
    // {
    //     $results = PackageDeliveryRequest::query()
    //         ->leftJoin('packages', 'packages.id', '=', 'package_delivery_requests.package_id')
    //         ->leftJoin('package_orders', 'package_orders.package_id', '=', 'packages.id')
    //         ->leftJoin('orders', 'orders.id', '=', 'package_orders.order_id')
    //         ->where('packages.captain_id', $captain->id)
    //         ->whereBetween('orders.delivery_date', [$businessDayStart, $businessDayEnd])
    //         ->whereNotNull('package_orders.order_id')
    //         ->groupBy(['package_orders.order_id', 'packages.id'])
    //         ->select(DB::raw('count(*) as count'))
    //         ->get();

    //     return $results->sum('count');
    // }

    public function onOfOrdersAccepted($captain, $businessDayStart, $businessDayEnd): int
    {
        return PackageDeliveryRequest::query()
            ->leftJoin('packages', 'packages.id', '=', 'package_delivery_requests.package_id')
            ->leftJoin('package_orders', 'package_orders.package_id', '=', 'packages.id')
            ->leftJoin('orders', 'orders.id', '=', 'package_orders.order_id')
            ->where('packages.captain_id', $captain->id)
            ->whereBetween('orders.delivery_date', [$businessDayStart, $businessDayEnd])
            ->whereNotNull('package_orders.order_id')
            ->groupBy('package_orders.order_id')
            ->select(DB::raw('count(*) as count'))
            ->get()
            ->count();
    }

    public function onOfOrdersDelivered($captain, $businessDayStart, $businessDayEnd): int
    {
        return Order::query()
            ->where('captain_id', $captain->id)
            ->whereBetween('orders.delivery_date', [$businessDayStart, $businessDayEnd])
            ->where('status_id', OrderStatus::DELIVERED)
            ->count();
    }

    public function onOfOrdersReturned($captain, $businessDayStart, $businessDayEnd): int
    {
        return PackageDeliveryRequest::query()
            ->leftJoin('packages', 'packages.id', '=', 'package_delivery_requests.package_id')
            ->leftJoin('package_orders', 'package_orders.package_id', '=', 'packages.id')
            ->leftJoin('orders', 'orders.id', '=', 'package_orders.order_id')
            ->where('packages.captain_id', $captain->id)
            ->whereBetween('orders.delivery_date', [$businessDayStart, $businessDayEnd])
            ->whereIn('orders.status_id', [OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::FORYOU_RETURN_ACCEPTED])
            ->whereNotNull('package_orders.order_id')
            ->groupBy('package_orders.order_id')
            ->select(DB::raw('count(*) as count'))
            ->get()
            ->count();
    }

    public function onOfOrdersCancelled($captain, $businessDayStart, $businessDayEnd): int
    {
        return PackageDeliveryRequest::query()
            ->leftJoin('packages', 'packages.id', '=', 'package_delivery_requests.package_id')
            ->leftJoin('package_orders', 'package_orders.package_id', '=', 'packages.id')
            ->leftJoin('orders', 'orders.id', '=', 'package_orders.order_id')
            ->where('packages.captain_id', $captain->id)
            ->whereBetween('orders.delivery_date', [$businessDayStart, $businessDayEnd])
            ->whereIn('orders.status_id', [OrderStatus::CANCEL, OrderStatus::CANCEL_REQUEST_ACCEPTED])
            ->whereNotNull('package_orders.order_id')
            ->groupBy('package_orders.order_id')
            ->select(DB::raw('count(*) as count'))
            ->get()
            ->count();
    }

    public function noOfOrdersReassigned($captain, $businessDayStart, $businessDayEnd): int
    {
        return PackageDeliveryRequest::query()
            ->leftJoin('packages', 'packages.id', '=', 'package_delivery_requests.package_id')
            ->leftJoin('package_orders', 'package_orders.package_id', '=', 'packages.id')
            ->leftJoin('orders', 'orders.id', '=', 'package_orders.order_id')
            ->where('packages.captain_id', $captain->id)
            ->whereBetween('orders.delivery_date', [$businessDayStart, $businessDayEnd])
            ->whereColumn('orders.captain_id', '<>', 'packages.captain_id')
            ->whereNotNull('package_orders.order_id')
            ->groupBy('package_orders.order_id')
            ->select(DB::raw('count(*) as count'))
            ->get()
            ->count();
    }
}
