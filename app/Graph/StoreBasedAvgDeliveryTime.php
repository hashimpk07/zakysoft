<?php
namespace App\Graph;

use App\Client;
use App\ClientShop;
use App\OrderReport;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;

class StoreBasedAvgDeliveryTime implements Graph
{
    public function data($request = null)
    {

        // $startDate = request()->has('from_date') ? now()->parse(request()->get('from_date'))->startOfDay() : now()->startOfDay();
        // $endDate = request()->has('to_date') ? now()->parse(request()->get('to_date'))->endOfDay() : now()->endOfDay();
        $fromDate = $request->get('from_date', now()->format('Y-m-d'));
        $toDate = $request->get('to_date', now()->format('Y-m-d'));

        //This is for showing the date range in the dashboard
        $fromDateTime = \Carbon\Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDateTime =  \Carbon\Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        //This is for filtering the orders based on the date range
        $startDate = $fromDateTime->format('Y-m-d H:i:s');
        $endDate =  $toDateTime->format('Y-m-d H:i:s');

        $client = request()->has('client') ? request()->get('client') : null;

        $colors = '#9BD0F5';
        $labels = [];
        $values = [];

        $store_name = Client::select('id', 'user_id')->with('user:name,id')->find($client);
        $title = "Consumed Delivery time by Store (" . $store_name->user->name . ")";

        $data = ClientShop::query()
            ->select('id', 'name')
            ->addSelect(['average_consumed_time' => OrderReport::query()
                    ->select(DB::raw('
                        DATE_FORMAT(
                            SEC_TO_TIME(AVG(TIMESTAMPDIFF(SECOND,
                                order_created_at,
                                IFNULL(final_status_at, NOW())
                            ))), "%H:%i:%s") as processing_time
                        ')
                    )
                    ->where('status_id', OrderStatus::DELIVERED)
                    ->whereColumn('order_reports.client_id', 'clients.id')
                    ->withinDateRange($startDate, $endDate, 'orders.delivery_date')
                    ->limit(1),
            ])
            ->where('client_id', $client)
            ->get();

        foreach ($data as $key => $value) {
            $labels[] = $value->name;
            $values[] = number_format($value->average_consumed_time, 2);
        }

        return compact('colors', 'labels', 'values', 'title');
    }
}
