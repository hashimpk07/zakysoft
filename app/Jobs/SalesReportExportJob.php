<?php

namespace App\Jobs;

use App\GeneralExport;
use App\Mail\ExportReportMail;
use App\Order;
use App\OrderStatus;
use App\Traits\HasFileUpload;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Psy\Readline\Hoa\Console;

class SalesReportExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HasFileUpload;

    public $timeout = 1200;
    private $export, $exportFileName, $page, $request;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        GeneralExport $export,
        string $exportFileName,
        int $page = 1,
        $request
    ) {
        $this->export = $export;
        $this->exportFileName = $exportFileName;
        $this->page = $page;
        $this->request = $request;
        $this->onQueue('reports');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try{
            $captain = isset($this->request['captain']) ? $this->request['captain'] : null;
            $client = isset($this->request['client']) ? $this->request['client'] : null;

            $path = 'public/general_exports/';

            $orders = $this->getOrders($this->request, false);
            if ($this->page == 1) {
                $columns = $this->getColumns($captain, $client);
                $page_count = $orders->lastPage();
                $this->export->update([
                    'page_count' => $page_count,
                ]);
            }

            $filesystemAdapter = Storage::disk('public');

            if ($this->export->status === 'pending') {
                $fileName =  $path. Carbon::now()->timestamp . '-' . $this->exportFileName . '-' . $this->export->created_by . '.csv';

                // add the headers only on the first run of this job... on subsequent runs, only append the data
                $fn = $this->createFile($fileName,implode(',', $columns). PHP_EOL);
            } else {
                $fileName = $this->export->file;
            }

            if ($this->export->status !== 'processing') {
                $this->export->update([
                    'status' => 'processing',
                    'status_message' => "Job {$this->page} in export processing started",
                    'file' => $fileName,
                    'page_done' => ($this->page - 1)
                ]);
            } elseif ($this->export->status === 'processing') {
                $this->export->update([
                    'status_message' => "Job {$this->page} in export processing started",
                    'file' => $fileName,
                    'page_done' => ($this->page - 1)
                ]);
            }

            $stream = $this->appendToTemp($fileName);

            if ($client && $captain) {
                foreach ($orders as $order) {
                    $paymentMode = $order->order_payment_mode ?? '';
                    $onTimePayment = $order->on_time_payment;
                    $billAmount = $order->amount;
                    $deliveryCharge = (float) $order->delivery_charge;
                    $deliveryChargeIncl = $order->delivery_charge_incl;
                    $vat_incl = $order->vat_incl;
                    $vat_rate = $order->vat_rate;

                    if ($vat_incl == 'No') {
                        $percent = round(($vat_rate / 100) * $deliveryCharge);
                        $deliveryCharge = (float) $deliveryCharge + (float) $percent;
                    } else {
                        $percent = round(($vat_rate / 100) * $deliveryCharge);
                        $deliveryCharge = (float) $deliveryCharge - (float) $percent;
                    }

                    $bank = 0;
                    $cash = 0;
                    $balance = 0;
                    $tot = 0;
                    if ($paymentMode == 'By Cash') {
                        $cash = $billAmount;
                    } elseif ($paymentMode == 'By POS') {
                        $bank = $billAmount;
                    } elseif ($paymentMode == 'Both') {
                        $bank = $order->pos_amount;
                        $cash = $order->cash;
                    }
                    $csvData =  [
                        $order->created_at ? \Carbon\Carbon::parse($order->created_at)->format('m-d-Y') : '',
                        $order->created_at ? \Carbon\Carbon::parse($order->created_at)->format('h:i:s A') : '',
                        sprintf("%'03d", $order->id),
                        $order->client_name ?? '',
                        isset($order->shop_name) ? $order->shop_name : $order->shopname,
                        $order->shop_area_name ?? '',
                        $order->shop_zone_name ?? '',
                        $order->client_order_id,
                        $order->shop_to_delivery_km,
                        $order->delivery_date ? \Carbon\Carbon::parse($order->delivery_date)->format('m-d-Y') : '',
                        $order->delivery_date ? \Carbon\Carbon::parse($order->delivery_date)->format('h:i:s A') : '',
                        $order->status,
                        $order->captain_name ?? '',
                        $order->iqama_number ?? '',
                        $paymentMode,
                        $billAmount,
                        $cash,
                        $bank,
                        $percent,
                        $deliveryCharge,
                        $order->note ?? '',
                    ];
                    
                    fputcsv($stream['stream'], $csvData);
                    
                }
            } else if ($client && empty($captain)) {
                foreach ($orders as $order) {
                    $paymentMode = $order->order_payment_mode ?? '';
                    $billAmount = $order->amount;
                    $deliveryChargeShow = $order->delivery_charge;
                    $indelCharge = 0;
                    $vat_incl = $order->vat_incl;
                    $vat_rate = $order->vat_rate;

                    $deliveryCharge = $order->delivery_charge;

                    if ($order->client_payment_mode == "Manual") {
                        $indelCharge = isset($order->fast_delivery_amount) ? $order->fast_delivery_amount : $order->scheduled_delivery_amount;
                        $deliveryCharge  = $indelCharge;
                    } else {
                        $deliveryCharge = $order->delivery_charge;
                    }

                    $percent = 0;
                    if ($vat_incl == 'No') {
                        $percent = round(($vat_rate / 100) * $deliveryCharge);
                        $deliveryCharge  = (float)$deliveryCharge + (float) $percent;
                    } else{
                        $percent = round(($vat_rate / 100) * $deliveryCharge);
                        $deliveryCharge  = (float)$deliveryCharge - (float) $percent;
                    }


                    $deliveryChargeIncl = $order->delivery_charge_incl;
                    $onTimePayment = $order->on_time_payment;
                    $cash = 0;
                    $bank = 0;
                    $balance = 0;
                    $tot = 0;
                    if ($onTimePayment == 'No') {
                        if ($deliveryChargeIncl == 'No') {
                            $cash = (float) $deliveryCharge + (float) $percent;
                            $bank = (float) $deliveryCharge + (float) $percent;
                            $balance = 0;
                            $bal = '-';
                        } else {
                            $balance = (float) $deliveryCharge + (float) $percent;
                            $bal = $balance;
                            $cash = (float) $order->cash;
                        }
                    } else {
                        $cash = $bank = (float) $billAmount;
                        $balance = (float) $billAmount - ((float) $deliveryCharge + (float) $percent);
                        $bal = $balance;
                    }
                    if ($order->delivery_payment_mode == Order::ORDER_PAYMENT_MODE_PRE_PAID || $order->delivery_payment_mode == Order::ORDER_PAYMENT_MODE_SWIPING_MACHINE) {
                        $balance = 0 - $deliveryCharge;
                    }

                    if ($paymentMode == 'By Cash') {
                        $cash = $cash;
                        $bank = 0;
                    } elseif ($paymentMode == 'By POS') {
                        $cash = 0;
                        $bank = $bank;
                    } elseif ($paymentMode == 'Both') {
                        if ($cash != 0 && $bank != 0) {
                            $cash = (float) $order->cash;
                            $bank = (float) $order->pos_amount;
                        }
                    } else {
                        $cash = 0;
                        $bank = 0;
                    }

                    if ($order->status == 'Canceled' & $cash == 0) {
                        $balance = 0;
                    }

                    if ($balance < 0) {
                        $balance = '(' . $balance . ')';
                    }

                    $csvData =  [
                        $order->created_at ? \Carbon\Carbon::parse($order->created_at)->format('m-d-Y') : '',
                        $order->created_at ? \Carbon\Carbon::parse($order->created_at)->format('h:i:s A') : '',
                        sprintf("%'03d", $order->id),
                        $order->captain_name ?? '',
                        $order->iqama_number ?? '',
                        $order->client_name ?? '',
                        isset($order->shop_name) ? $order->shop_name : $order->shopname,
                        $order->shop_area_name ?? '',
                        $order->shop_zone_name ?? '',
                        $order->client_order_id,
                        $order->shop_to_delivery_km,
                        $order->delivery_date ? \Carbon\Carbon::parse($order->delivery_date)->format('m-d-Y') : '',
                        $order->delivery_date ? \Carbon\Carbon::parse($order->delivery_date)->format('h:i:s A') : '',
                        $order->status,
                        $paymentMode,
                        $billAmount,
                        $deliveryChargeShow,
                        $deliveryCharge,
                        $percent,
                        $cash,
                        $bank,
                        $balance,
                        $order->note ?? '',
                    ];
                
                    fputcsv($stream['stream'], $csvData);

                }
            } else if ($captain && empty($client)) {
                foreach ($orders as $order) {

                    $paymentMode = $order->order_payment_mode ?? '';
                    $billAmount = $order->amount;
                    $deliveryCharge = $order->delivery_charge;
                    $deliveryChargeIncl = $order->delivery_charge_incl;
                    $onTimePayment = $order->on_time_payment;
                    $pos_amt = 0;
                    $debit = 0;
                    $bank = 0;
                    $cash = 0;
                    $balance = 0;
                    $bal = 0;
                    if ($onTimePayment == 'No' && $paymentMode != '') {
                        if ($deliveryChargeIncl == 'No') {
                            $debit = $billAmount - $deliveryCharge;
                        } else {
                            $debit = $billAmount;
                        }
                    }
                    if ($paymentMode == 'By Cash') {
                        $cash = $billAmount;
                        $balance = (float) $cash - (float) $debit;
                        $bal = (float) $balance;
                    } elseif ($paymentMode == 'By POS') {
                        $bank = $billAmount;

                        if ($debit > 0) {
                            if ($debit == $billAmount - $deliveryCharge) {
                                $pos_amt = 0;
                                $balance = (float) $pos_amt - (float) $debit;
                                $bal = $balance;
                            } else {
                                $balance = (float) $bank - (float) $debit;
                                $bal = '(' . $balance . ')';
                            }
                        }
                    } elseif ($paymentMode == 'Both') {
                        $cash = $order->cash;
                        $bank = $order->pos_amount;
                        $balance = (float) $cash;
                        $bal = $balance;
                    }


                    if ($balance < 0) {
                        $bal = '(' . $balance . ')';
                    }

                $csvData =   [
                    $order->created_at ? \Carbon\Carbon::parse($order->created_at)->format('m-d-Y') : '',
                    $order->created_at ? \Carbon\Carbon::parse($order->created_at)->format('h:i:s A') : '',
                    sprintf("%'03d", $order->id),
                    $order->captain_name ?? '',
                    $order->iqama_number ?? '',
                    $order->client_name ?? '',
                    isset($order->shop_name) ? $order->shop_name : $order->shopname,
                    $order->shop_area_name ?? '',
                    $order->shop_zone_name ?? '',
                    $order->client_order_id,
                    $order->shop_to_delivery_km,
                    $order->delivery_date ? \Carbon\Carbon::parse($order->delivery_date)->format('m-d-Y') : '',
                    $order->delivery_date ? \Carbon\Carbon::parse($order->delivery_date)->format('h:i:s A') : '',
                    $order->status,
                    $paymentMode,
                    $billAmount,
                    $debit,
                    $bank,
                    $cash,
                    $bal,
                    $order->note ?? '',
                ];

                fputcsv($stream['stream'], $csvData);
                }
            } else {
                foreach ($orders as $order) {

                    $paymentMode = $order->order_payment_mode ?? '';
                    $onTimePayment = $order->on_time_payment;
                    $billAmount = $order->amount;
                    $deliveryCharge = (float)$order->delivery_charge;
                    $deliveryChargeIncl = $order->delivery_charge_incl;
                    $vat_incl = $order->vat_incl;
                    $vat_rate = $order->vat_rate;
                    $percent = 0;
                    if ($vat_incl == 'No') {
                        $percent = round(($vat_rate / 100) * $deliveryCharge);
                        $deliveryCharge  = (float)$deliveryCharge + (float) $percent;
                    } else {
                        $percent = round(($vat_rate / 100) * $deliveryCharge);
                        $deliveryCharge  = (float)$deliveryCharge - (float) $percent;
                    }
                    $bank = 0;
                    $cash = 0;
                    $balance = 0;
                    $tot = 0;
                    if ($paymentMode == 'By Cash') {
                        $cash = $billAmount;
                    } else if ($paymentMode == 'By POS') {
                        $bank =  $billAmount;
                    } else if ($paymentMode == 'Both') {
                        $bank =  $order->pos_amount;
                        $cash = $order->cash;
                    }
                    $csvData = [
                        $order->created_at ? \Carbon\Carbon::parse($order->created_at)->format('m-d-Y') : '',
                        $order->created_at ? \Carbon\Carbon::parse($order->created_at)->format('h:i:s A') : '',
                        sprintf("%'03d", $order->id),
                        $order->client_name,
                        isset($order->shop_name) ? $order->shop_name : $order->shopname,
                        $order->shop_area_name ?? '',
                        $order->shop_zone_name ?? '',
                        $order->client_order_id,
                        $order->shop_to_delivery_km,
                        $order->delivery_date ? \Carbon\Carbon::parse($order->delivery_date)->format('m-d-Y') : '',
                        $order->delivery_date ? \Carbon\Carbon::parse($order->delivery_date)->format('h:i:s A') : '',
                        $order->status,
                        $order->captain_name ?? '',
                        $order->iqama_number ?? '',
                        $paymentMode,
                        $billAmount,
                        $cash,
                        $bank,
                        $percent,
                        $deliveryCharge,
                        $order->note ?? '',

                    ];

                    fputcsv($stream['stream'], $csvData);
                }
            }

            $path = $this->putData($stream['tempLocalFilePath'],$fileName);
            fclose($stream['stream']);

            $nextPageUrl = $orders->nextPageUrl();
            $nextPage = null;
            if (!is_null($nextPageUrl)) {
                $nextPage = explode('=', $nextPageUrl, 2)[1];
            }

            if (is_null($nextPage)) {
                // we are done processing
                $this->export->update([
                    'status' => 'processed',
                    'status_message' => 'Completed',
                    'is_ready_for_download' => 1,
                    'notify' => 1,
                    'page_done' => $this->page
                ]);
            
                Mail::to($this->export->email_id)->send(new ExportReportMail($this->export));
                return;
            }

            if ($this->export->status !== 'error') {
                // refresh to get current state of export before using it for next job
                $this->export->refresh();

                dispatch(new static($this->export, $fileName, $nextPage, $this->request));
            }
        }catch(Exception $e){
            $this->export->update([
                'status' => 'error',
                'status_message' => 'Error',
                'is_ready_for_download' => 0,
                'notify' => 1,
                'page_done' => $this->page
            ]);
            Mail::to($this->export->email_id)->send(new ExportReportMail($this->export));

            return;
        }
    }

    public function getColumns($captain = null, $client = null)
    {
        if ($client && $captain) {
            return $columns = [
                'Order Date','Order Time', 'Order No', 'Client Name', 'Shop Name', "Area", "Zone", 'AWB', 'Dist. b/w Shop & Dlvry', 'Delivered Date','Delivered Time', 'Order Status', 'Captain', 'Iqama No', 'Payment Type', 'Bill Amount', 'Cash', 'Bank', 'Vat Amount', 'Delivery Charge', 'Note'
            ];
        } else if ($client && empty($captain)) {
            return $columns = [
                'Order Date','Order Time', 'Order No', 'Captain', 'Iqama No', 'Client Name', 'Shop Name', "Area", "Zone", 'AWB', 'Dist. b/w Shop & Dlvry', 'Delivered Date','Delivered Time', 'Order Status', 'Payment Mode', 'Bill Amount', 'Delivery Charge', 'Internal Delivery Charge', 'Vat Amount', 'Cash', 'Bank', 'Balance', 'Note'
            ];
        } else if ($captain && empty($client)) {
            return $columns = [
                'Order Date','Order Time', 'Order No', 'Captain', 'Iqama No', 'Client Name', 'Shop Name', "Area", "Zone", 'AWB', 'Dist. b/w Shop & Dlvry', 'Delivered Date','Delivered Time', 'Order Status', 'Payment Mode', 'Bill Amount', 'Debit', 'Bank', 'Cash', 'Balance', 'Note'
            ];
        } else {
            return $columns = [
                'Order Date','Order Time', 'Order No', 'Client Name', 'Shop Name', "Area", "Zone", 'AWB', 'Dist. b/w Shop & Dlvry', 'Delivered Date','Delivered Time', 'Order Status', 'Captain', 'Iqama No', 'Payment Type', 'Bill Amount', 'Cash', 'Bank', 'Vat Amount', 'Delivery Charge', 'Note'
            ];
        }
    }

    public function getOrders($request, $only_count)
    {

        $fromDate = isset($request['from_date']) ? Carbon::parse($request['from_date'])->format('Y-m-d') : Carbon::now()->subDays(6)->format('Y-m-d');
        $toDate = isset($request['to_date']) ? Carbon::parse($request['to_date'])->addDay()->format('Y-m-d') : Carbon::now()->addDay()->format('Y-m-d');
        $date_from = isset($request['order_time_from']) ? $fromDate . ' ' . $request['order_time_from'] : $fromDate . ' ' . '06:00';
        $date_to = isset($request['order_time_to']) ? $toDate . ' ' . $request['order_time_to'] : $toDate . ' ' . '05:59';

        $orders = DB::table('orders')
            ->select(
                'orders.id',
                DB::raw("DATE_FORMAT(orders.created_at, '%Y-%m-%d %H:%i') as created_at"),
                'orders.code',
                'orders.delivery_payment_mode',
                'client.name as client_name',
                'client_shops.name as shop_name',
                'orders.client_order_id',
                'order_statuses.name as status',
                'captain.name as captain_name',
                'captains.iqama_number as iqama_number',
                DB::raw("(CASE WHEN order_payments.payment_mode THEN order_payments.payment_mode ELSE orders.delivery_payment_mode END) as order_payment_mode"),
                'clients.on_time_payment',
                'orders.amount',
                'orders.delivery_charge',
                'clients.delivery_charge_incl',
                'clients.vat_incl',
                'orders.vat_rate',
                'clients.payment_mode as client_payment_mode',
                'clients.fast_delivery_amount',
                'clients.scheduled_delivery_amount',
                'order_payments.pos_amount',
                'order_payments.cash',
                'orders.status_id',
                'orders.shop_to_delivery_km',
                'orders.delivery_date',
                DB::raw('COALESCE(notes.note, order_pending_reasons.reason,order_logs.note) as note'),
                'shop_zone.name as shop_zone_name',
                'shop_region.name as shop_area_name',
            )
            ->leftJoin('clients', 'clients.id', 'orders.client_id')
            ->leftJoin('captains', 'captains.id', 'orders.captain_id')
            ->leftJoin('order_statuses', 'order_statuses.id', 'orders.status_id')
            ->leftJoin('users as captain', 'captain.id', 'captains.user_id')
            ->leftJoin('users as client', 'client.id', 'clients.user_id')
            ->leftJoinSub(DB::table('order_payments')->selectRaw('MAX(id) max_id, order_id')->groupBy('order_id'), 'last_payment_id', function ($join) {
                $join->on('orders.id', '=', 'last_payment_id.order_id');
            })
            ->leftJoin('order_payments', 'order_payments.id', 'last_payment_id.max_id')
            // ->leftJoin('order_payments', 'order_payments.order_id', 'orders.id')
            ->leftJoin('client_shops', 'client_shops.id', 'shopname')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
            ->leftJoin('order_logs', function ($query) {
                $query->on('order_logs.order_id', '=', 'orders.id')
                    ->whereRaw('order_logs.id IN (select MAX(last_logs.id) from order_logs as last_logs join orders as u2 on u2.id = last_logs.order_id and (last_logs.reason_id is NOT NULL OR last_logs.note is NOT NULL) and u2.status_id != 10 group by u2.id)');
            })
            ->leftJoin('order_pending_reasons', 'order_logs.reason_id', 'order_pending_reasons.id')
            ->leftJoin('notes', function ($query) {
                $query->on('notes.order_id', '=', 'orders.id')
                    ->whereRaw('notes.id IN (select MAX(last_note.id) from notes as last_note join orders as u2 on u2.id = last_note.order_id group by u2.id)');
            })
            ->whereIn('orders.status_id', [OrderStatus::SHIPPED, OrderStatus::DELIVERED, OrderStatus::CANCEL, OrderStatus::FORYOU_RETURN_ACCEPTED, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED]);

        if (isset($request['client'])) {
            $orders = $orders->where('orders.client_id', $request['client']);
        }
        if (isset($request['captain'])) {
            $orders = $orders->where('orders.captain_id', $request['captain']);
        }

        if (isset($request['orderID'])) {
            $orders = $orders->where('orders.client_order_id', 'LIKE', $request['orderID'] . '%');
        }

        if (isset($request['shop'])) {
            $orders = $orders->where('orders.shopname', $request['shop']);
        }
        $orders = $orders->whereBetween('orders.delivery_date', [$date_from, $date_to])
            ->orderBy('orders.created_at', 'asc');


        if ($only_count == true) {
            return  $orders->count();
        } else {
            $orders = $orders->paginate(10000, ['*'], 'page', $this->page);
            return $orders;
        }
    }

    public function failed(Exception $exception)
    {
        $this->export->update([
            'status' => 'error',
            'status_message' => 'Error',
            'is_ready_for_download' => 0,
            'notify' => 1,
            'page_done' => $this->page
        ]);
        Mail::to($this->export->email_id)->send(new ExportReportMail($this->export));
    }
}
