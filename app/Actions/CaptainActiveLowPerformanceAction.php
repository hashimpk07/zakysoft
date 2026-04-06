<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\OrderStatus;
use App\Captain;
use App\CaptainPerformanceReport;

class CaptainActiveLowPerformanceAction
{
    public function execute(): void
    {
        $businessDay = now();
        $from_date = $businessDay->copy()->setTime(6, 0, 0);
        $to_date = $businessDay->copy()->addDay()->setTime(5, 59, 59);

        // Calculate total work time per captain
        $workTimes = DB::table('shift_statuses')
            ->select('captain_id', DB::raw('SUM(TIMESTAMPDIFF(SECOND, shift_start, IFNULL(shift_end, NOW()))) as total_work_time_in_seconds'))
            ->whereBetween('created_at', [$from_date, $to_date])
            ->groupBy('captain_id');

        // Orders received per captain
        $ordersReceived = DB::table('package_delivery_requests')
            ->join('packages', 'packages.id', '=', 'package_delivery_requests.package_id')
            ->join('package_orders', 'package_orders.package_id', '=', 'packages.id')
            ->join('orders', 'orders.id', '=', 'package_orders.order_id')
            ->select('package_delivery_requests.captain_id', DB::raw('COUNT(DISTINCT orders.id) as received_order'))
            ->whereBetween('orders.created_at', [$from_date, $to_date])
            ->groupBy('package_delivery_requests.captain_id');

        // Orders accepted per captain
        $ordersAccepted = DB::table('packages')
            ->join('package_orders', 'package_orders.package_id', '=', 'packages.id')
            ->join('orders', 'orders.id', '=', 'package_orders.order_id')
            ->select('packages.captain_id', DB::raw('COUNT(DISTINCT orders.id) as accepted_order'))
            ->whereBetween('orders.created_at', [$from_date, $to_date])
            ->groupBy('packages.captain_id');

        // Orders delivered per captain
        $ordersDelivered = DB::table('orders')
            ->select('captain_id', DB::raw('COUNT(*) as delivered_orders'))
            ->where('status_id', OrderStatus::DELIVERED)
            ->whereBetween('created_at', [$from_date, $to_date])
            ->groupBy('captain_id');

       
        $reportData = DB::table('captains')
            ->select(
                'captains.id as captain_id',
                DB::raw('COALESCE(w.total_work_time_in_seconds, 0) as total_work_time_in_seconds'),
                DB::raw('COALESCE(r.received_order, 0) as received_order'),
                DB::raw('COALESCE(a.accepted_order, 0) as accepted_order'),
                DB::raw('COALESCE(d.delivered_orders, 0) as delivered_orders')
            )
            ->leftJoinSub($workTimes, 'w', 'w.captain_id', '=', 'captains.id')
            ->leftJoinSub($ordersReceived, 'r', 'r.captain_id', '=', 'captains.id')
            ->leftJoinSub($ordersAccepted, 'a', 'a.captain_id', '=', 'captains.id')
            ->leftJoinSub($ordersDelivered, 'd', 'd.captain_id', '=', 'captains.id')
            ->whereIn('captains.id', Captain::online()->pluck('id'))
            ->havingRaw('received_order < 5 AND (CASE WHEN total_work_time_in_seconds > 0 THEN received_order / (total_work_time_in_seconds / 3600) < 1 ELSE 1 END)')
            ->get();

        
        $insertData = $reportData->map(function ($item) use ($businessDay) {
            return [
                'captain_id' => $item->captain_id,
                'report_for' => $businessDay->format('Y-m-d'),
                'total_work_time_in_seconds' => $item->total_work_time_in_seconds,
                'received_order' => $item->received_order,
                'accepted_order' => $item->accepted_order,
                'delivered_orders' => $item->delivered_orders,
                'updated_at' => now(),
                'created_at' => now(),
            ];
        })->toArray();

        // CaptainPerformanceReport::upsert(
        //     $insertData,
        //     ['captain_id', 'report_for'], 
        //     [
        //         'total_work_time_in_seconds',
        //         'received_order',
        //         'accepted_order',
        //         'delivered_orders',
        //         'updated_at',
        //     ]
        // );

        foreach ($insertData as $data) {
            $existing = CaptainPerformanceReport::where('captain_id', $data['captain_id'])
                ->whereDate('report_for', $data['report_for'])
                ->first();

        if ($existing) {
            $existing->update([
                'total_work_time_in_seconds' => $data['total_work_time_in_seconds'],
                'received_order' => $data['received_order'],
                'accepted_order' => $data['accepted_order'],
                'delivered_orders' => $data['delivered_orders'],
                'updated_at' => now(),
            ]);
        } else {
            CaptainPerformanceReport::create($data);
        }
    }

    
        CaptainPerformanceReport::where('accepted_order', '>=', 5)->delete();

        Log::channel('commission')->info('Captain performance batch report generated', [
            'data' => $insertData ,
            'count' => count($insertData),
            'date' => $businessDay->format('Y-m-d'),
        ]);
    }

}
