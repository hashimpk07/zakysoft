<?php

namespace App\Exports;

use App\Captain;
use App\Order;
use App\OrderStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;

class CaptainPerformanceExport implements FromView
{
    use Exportable;

    public function __construct()
    {}
    /**
     * @return \Illuminate\Support\Collection
     */
    public function view(): View
    {
        $from_date = request()->get('from_date', now()->startOfMonth()->format('Y-m-d'));
        $to_date = request()->get('to_date', now()->format('Y-m-d'));
        $captain = request()->get('captain', false);
        $client = request()->get('client', false);
        $region = request()->get('region', false);

        $performance_reports = Captain::query()
                                ->with('regions')
                                ->select(
                                    'captains.id', 
                                    DB::raw('CONCAT(captains.firstname, " ", captains.lastname) as full_name'), 
                                    'captains.code', 
                                    'captains.job_type'
                                )
                                ->addSelect(
                                    DB::raw('(select SUM(TIMESTAMPDIFF(SECOND,shift_statuses.shift_start, IFNULL(shift_statuses.shift_end, now())))
                                        FROM shift_statuses 
                                        WHERE 
                                            shift_statuses.captain_id = captains.id'.
                                            ($from_date ? ' AND shift_statuses.created_at >= "'.now()->parse($from_date)->format('Y-m-d 00:00:00') .'"' : '') .
                                            ($to_date ? ' AND shift_statuses.created_at <= "'.now()->parse($to_date)->format('Y-m-d 23:59:59').'"' : '') .
                                        ' GROUP BY shift_statuses.captain_id
                                    ) as total_work_time_in_seconds')
                                )
                                ->addSelect(
                                    DB::raw('(SELECT COUNT(*) FROM (SELECT count(*)
                                                FROM shift_statuses 
                                                WHERE 
                                                    shift_statuses.captain_id = captains.id'.
                                                    ($from_date ? ' AND shift_statuses.created_at >= "'.now()->parse($from_date)->format('Y-m-d 00:00:00') .'"' : '') .
                                                    ($to_date ? ' AND shift_statuses.created_at <= "'.now()->parse($to_date)->format('Y-m-d 23:59:59').'"' : '') .
                                                ' GROUP BY CAST(created_at AS DATE)
                                            ) as no_of_shifts_days_worked) as no_of_days_worked')
                                )
                                ->addSelect(
                                    DB::raw('(SELECT COUNT(*) 
                                            FROM (SELECT  COUNT(*)
                                                FROM
                                                    package_delivery_requests
                                                    LEFT JOIN packages ON packages.id = package_delivery_requests.package_id
                                                    LEFT JOIN package_orders ON package_orders.package_id = packages.id
                                                    LEFT JOIN orders ON orders.id = package_orders.order_id
                                                WHERE
                                                    package_delivery_requests.captain_id = captains.id AND orders.id IS NOT NULL'.
                                                    ($from_date ? ' AND package_delivery_requests.sended_at >= "'.now()->parse($from_date)->format('Y-m-d 00:00:00') .'"' : '') .
                                                    ($to_date ? ' AND package_delivery_requests.sended_at <= "'.now()->parse($to_date)->format('Y-m-d 23:59:59').'"' : '') .
                                                    ($client ? ' AND orders.client_id = '.$client : '') .
                                                ' GROUP BY orders.id
                                            ) as no_of_times_orders_sent
                                        ) as no_of_orders_sent')
                                )
                                ->addSelect(
                                    DB::raw('(SELECT COUNT(*) 
                                        FROM (SELECT  COUNT(*)
                                            FROM
                                                packages
                                                LEFT JOIN package_orders ON package_orders.package_id = packages.id
                                                LEFT JOIN orders ON orders.id = package_orders.order_id
                                            WHERE
                                            packages.captain_id = captains.id AND orders.id IS NOT NULL'.
                                            ($from_date ? ' AND packages.created_at >= "'.now()->parse($from_date)->format('Y-m-d 00:00:00') .'"' : '') .
                                            ($to_date ? ' AND packages.created_at <= "'.now()->parse($to_date)->format('Y-m-d 23:59:59').'"' : '') .
                                            ($client ? ' AND orders.client_id = '.$client : '') .
                                        ' GROUP BY orders.id
                                        ) as no_of_times_orders_accepted
                                    ) as no_of_orders_accepted')
                                )
                                ->addSelect(
                                    DB::raw('(SELECT  COUNT(*)
                                            FROM
                                                orders
                                            WHERE
                                                orders.captain_id = captains.id AND
                                                orders.status_id = '. OrderStatus::DELIVERED .
                                                ($from_date ? ' AND orders.created_at >= "'.now()->parse($from_date)->format('Y-m-d 00:00:00') .'"' : '') .
                                                ($to_date ? ' AND orders.created_at <= "'.now()->parse($to_date)->format('Y-m-d 23:59:59').'"' : '') .
                                                ($client ? ' AND orders.client_id = '.$client : '') .
                                            ') as no_of_completed_orders')
                                )
                                ->addSelect(
                                    DB::raw('(SELECT  COUNT(*)
                                                FROM
                                                    orders
                                                WHERE
                                                    orders.captain_id = captains.id AND
                                                    orders.status_id = '. OrderStatus::CLIENT_RETURN_ACCEPTED .
                                                    ($from_date ? ' AND orders.created_at >= "'.now()->parse($from_date)->format('Y-m-d 00:00:00') .'"' : '') .
                                                    ($to_date ? ' AND orders.created_at <= "'.now()->parse($to_date)->format('Y-m-d 23:59:59').'"' : '') .
                                                    ($client ? ' AND orders.client_id = '.$client : '') .
                                                ') as no_of_client_returned_orders')
                                )
                                ->addSelect(
                                    DB::raw('(SELECT  COUNT(*)
                                            FROM
                                                orders
                                            WHERE
                                                orders.captain_id = captains.id AND
                                                orders.status_id = '. OrderStatus::FORYOU_RETURN_ACCEPTED .
                                                ($from_date ? ' AND orders.created_at >= "'.now()->parse($from_date)->format('Y-m-d 00:00:00') .'"' : '') .
                                                ($to_date ? ' AND orders.created_at <= "'.now()->parse($to_date)->format('Y-m-d 23:59:59').'"' : '') .
                                                ($client ? ' AND orders.client_id = '.$client : '') .
                                            ') as no_of_foryou_returned_orders')
                                )
                                ->addSelect(
                                    DB::raw('(
                                        SELECT COUNT(*)
                                             FROM
                                                 package_delivery_requests
                                                 LEFT JOIN packages ON packages.id = package_delivery_requests.package_id
                                                 LEFT JOIN package_orders ON package_orders.package_id = packages.id
                                                 LEFT JOIN orders ON orders.id = package_orders.order_id
                                             WHERE  
                                                 package_delivery_requests.captain_id = captains.id 
                                                 AND package_delivery_requests.attempted_at IS NOT NULL' .
                                                 ($from_date ? ' AND package_delivery_requests.sended_at >= "'.now()->parse($from_date)->format('Y-m-d 00:00:00') .'"' : '') .
                                                 ($to_date ? ' AND package_delivery_requests.sended_at <= "'.now()->parse($to_date)->format('Y-m-d 23:59:59').'"' : '') .
                                                 ($client ? ' AND orders.client_id = '.$client : '') .
                                        ') as no_of_trying_to_accept_orders'
                                     )     
                                 )
                                 ->addSelect(
                                     DB::raw('(
                                        SELECT COUNT(*)
                                            FROM
                                                package_delivery_requests
                                                LEFT JOIN packages ON packages.id = package_delivery_requests.package_id
                                                LEFT JOIN package_orders ON package_orders.package_id = packages.id
                                                LEFT JOIN orders ON orders.id = package_orders.order_id
                                            WHERE  
                                                package_delivery_requests.captain_id = captains.id 
                                                AND package_delivery_requests.attempted_at IS NULL
                                                AND packages.captain_id IS NOT NULL
                                                AND TIMESTAMPDIFF(MINUTE, package_delivery_requests.sended_at, NOW()) >= 3' .
                                                ($from_date ? ' AND package_delivery_requests.sended_at >= "'.now()->parse($from_date)->format('Y-m-d 00:00:00') .'"' : '') .
                                                ($to_date ? ' AND package_delivery_requests.sended_at <= "'.now()->parse($to_date)->format('Y-m-d 23:59:59').'"' : '') .
                                                ($client ? ' AND orders.client_id = '.$client : '') .
                                     ') as no_of_no_response_requests')
                                 )
                                 ->addSelect(
                                     DB::raw('(
                                        SELECT COUNT(*)
                                            FROM
                                                package_delivery_requests
                                                LEFT JOIN packages ON packages.id = package_delivery_requests.package_id
                                                LEFT JOIN package_orders ON package_orders.package_id = packages.id
                                                LEFT JOIN orders ON orders.id = package_orders.order_id
                                            WHERE  
                                                package_delivery_requests.captain_id = captains.id 
                                                AND package_delivery_requests.declined_at IS NOT NULL' .
                                                ($from_date ? ' AND package_delivery_requests.sended_at >= "'.now()->parse($from_date)->format('Y-m-d 00:00:00') .'"' : '') .
                                                ($to_date ? ' AND package_delivery_requests.sended_at <= "'.now()->parse($to_date)->format('Y-m-d 23:59:59').'"' : '') .
                                                ($client ? ' AND orders.client_id = '.$client : '') .
                                     ') as no_of_declined_requests')
                                 )
                                ->when($captain, function($query, $captain) {
                                    return $query->where('captains.id', $captain);
                                })
                                ->when($region, function($query, $region) {
                                    return $query->whereHas('regions', function($query) use($region){
                                        $query->where('id', $region);
                                    });
                                })
                                ->get();
                                
        return view('exports.captain-performance', compact('performance_reports', 'from_date', 'to_date', 'client'));
    }
}