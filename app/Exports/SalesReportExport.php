<?php

namespace App\Exports;

use App\Order;
use App\Captain;
use App\Client;
use App\OrderStatus;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesReportExport implements FromView
{
    use Exportable;

    private $shop,$client_order_id,$from,$to,$client_id,$captain_id;

    public function __construct(string $shop=null,string $client_order_id=null,string $from=null,string $to=null,string $client_id=null,string $captain_id=null)
    {
        $this->shop = $shop;
        $this->client_order_id = $client_order_id;
        $this->from = $from;
        $this->to = $to;
        $this->captain_id = $captain_id;
        $this->client_id = $client_id;
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function view(): View
    {
        $input            = request()->all();

        $captain          = $this->captain_id;
        $client           = $this->client_id;
        $to_date          = $this->to;
        $from_date        = $this->from;
        $shop             = $this->shop;
        $client_order_id  = $this->client_order_id;

        $request_order_time_from = $input['request_order_time_from'];
        $request_order_time_to = $input['request_order_time_to'];


        $orders = Order::
                    select('orders.*', 'order_logs.created_at as last_order_status_changed_at')
                    ->addSelect(DB::raw('COALESCE(notes.note, order_pending_reasons.reason) as note'))
                    ->with('client', 'captain', 'payment','shop', 'progress')
                    ->whereIn('orders.status_id',[OrderStatus::SHIPPED, OrderStatus::DELIVERED, OrderStatus::CANCEL, OrderStatus::FORYOU_RETURN_ACCEPTED, OrderStatus::CLIENT_RETURN_ACCEPTED])
                    ->leftJoin('order_logs', function($query) {
                        $query->on('order_logs.order_id','=','orders.id')
                            ->whereRaw('order_logs.id IN (select MAX(last_logs.id) from order_logs as last_logs join orders as u2 on u2.id = last_logs.order_id group by u2.id)');
                    })
                    ->leftJoin('order_pending_reasons', 'order_logs.reason_id', 'order_pending_reasons.id')
                    ->leftJoin('notes', function($query) {
                        $query->on('notes.order_id', '=', 'orders.id')
                            ->whereRaw('notes.id IN (select MAX(last_note.id) from notes as last_note join orders as u2 on u2.id = last_note.order_id group by u2.id)');
                    });

        if($shop != "") {
            $orders = $orders->where('shopname',$shop);
        }
        if($client_order_id != "") {
            $orders = $orders->where('client_order_id',$client_order_id);
        }
        
        if($from_date != "" || $request_order_time_from != "") {
            $date_from = now()->parse(($from_date ?? now()->format('d-m-Y')) . ' ' . ($request_order_time_from ?? '00:00:00'));
            $orders = $orders->where('order_logs.created_at', '>=', $date_from->format('Y-m-d H:i:s'));;
        }
        
        if($to_date != "" || $request_order_time_to != "") {
            $date_to = now()->parse(($to_date ?? now()->format('d-m-Y')) . ' ' . ($request_order_time_to ?? '23:59'));
            $orders = $orders->where('order_logs.created_at', '<=', $date_to->format('Y-m-d H:i:s'));;
        }

        if($client != "") {
            $clientDetails = Client::find($client);
            $orders = $orders->where('client_id',$client);
            $orders = $orders->get();
            return view('reports.export_salesreport_client', [
                'orders' => $orders,'clientDetails' => $clientDetails,
            ]);
        }
        if($captain != "") {
            $captainDetails = Captain::find($captain);
            $orders = $orders->where('orders.captain_id',$captain);
            $orders = $orders->get();
            return view('reports.export_salesreport_captain', [
                'orders' => $orders,'captainDetails' => $captainDetails
            ]);
        } else {
            $orders = $orders->get();
            return view('reports.export_salesreport', [
                'orders' => $orders,
            ]);
        }

    }
}