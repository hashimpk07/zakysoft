<?php

namespace App\Jobs;

use App\GeneralExport;
use App\Mail\ExportReportMail;
use App\Order;
use App\OrderStatus;
use App\ThirdPartyLogisticCompanyUser;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use App\Traits\HasFileUpload;
use Exception;
use Illuminate\Support\Facades\Log;
use Str;

class ThirdPartyOrderExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HasFileUpload;

    public $timeout = 1200;
    private $export, $exportFileName, $page, $request, $company_id_3pl;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        GeneralExport $export,
        string $exportFileName,
        int $page = 1,
        $request,
        $company_id_3pl
    ) {
        $this->onQueue('reports');
        $this->export = $export;
        $this->exportFileName = $exportFileName;
        $this->page = $page;
        $this->request = $request;
        $this->company_id_3pl = $company_id_3pl;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try{
            $path = 'public/general_exports/';

            $orders = $this->getOrders($this->request);
            if ($this->page == 1) {
                $columns = $this->getColumns();
                $page_count = $orders->lastPage();
                $this->export->update([
                    'page_count' => $page_count,
                ]);
            }

            $filesystemAdapter = Storage::disk('public');
            
            if ($this->export->status === 'pending') {
                $fileName =  $path.Carbon::now()->timestamp . '-' . $this->exportFileName . '-' . $this->export->created_by . '.csv';

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
        
            foreach ($orders as $order) {  
                $captain = $order->captain? $order->captain->user->name : 'Not Assigned';
                fputcsv(
                    $stream['stream'],
                    [
                        'OR#'.sprintf('%03d', $order->id),
                        '#'. $order->client_order_id,
                        $order->client_name ?? "",
                        $order->shop->name? $order->shop->name : $order->shopname,
                        $order->region_name ?? (isset($order->shop->region->name) ? $order->shop->region->name : ""),
                        $order->zone_name ?? (isset($order->shop->zone->name) ? $order->shop->zone->name : ""),
                        ($order->amount ?? 0) . ' SAR',
                        $order->delivery_type,
                        $order->created_at->format('Y-m-d'),
                        $order->progress->name,
                        $captain 
                    ]
                );
        }
        $this->putData($stream['tempLocalFilePath'],$fileName);

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
                    'status_message' => 'Export file processed successfully and ready for download',
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
                dispatch(new static($this->export, $fileName, $nextPage, $this->request,$this->company_id_3pl));
            }
         
        }catch(Exception $exception){
            Log::error($exception->getMessage() . ' at line ' . $exception->getLine(). ' in ' . $exception->getFile());
            $this->export->update([
                'status' => 'error',
                'status_message' => $exception->getMessage() . ' at line ' . $exception->getLine(). ' in ' . $exception->getFile(),
                'is_ready_for_download' => 0,
                'notify' => 1,
            ]);

            Mail::to($this->export->email_id)->send(new ExportReportMail($this->export));

            throw $exception;
        }
    }

    public function getColumns()
    {
        
        return [
            'Order ID', 'Client ID', 'Client Name', 'Shop Name', "Area", "Zone", 'Amount', 'Type', 'Created At', 'Status', 'Assigned Captain'
        ];
     
    }

    public function getOrders($request)
    {
        $status = isset($request['status']) ? $request['status'] : '';
        $clients = isset($request['clients']) ? $request['clients'] : NULL;
        $shop_name = isset($request['shopname']) ? $request['shopname'] : NULL;
        $order_id = isset($request['orderID']) ? $request['orderID'] : '';
        $order_type = isset($request['order_type']) ? $request['order_type'] : NULL;
        $time_slot = isset($request['time_slot'])? $request['time_slot'] : '';
        $from_date = isset($request['from_date']) ? $request['from_date'] : '';
        $to_date =isset( $request['to_date'] ) ? $request['to_date'] : '' ;
        $zone = isset($request['zone']) ? $request['zone'] :'';
        $region = isset($request['region']) ? $request['region'] : '' ;
        $captain = isset($request['captain']) ? $request['captain'] : '';

        if($clients && !is_array($clients)) {
           $clients = explode(',', $clients);
       }
       if($shop_name && !is_array($shop_name)) {
           $shop_name = explode(',', $shop_name);
       }

     $orders = Order::select('orders.code', 'orders.client_order_id', 'orders.amount', 'orders.delivery_charge', 'orders.delivery_date', 'orders.created_at', 'orders.status_id', 'orders.id', 'orders.delivery_time', 'orders.client_id', 'orders.captain_id', 'orders.zone_id', 'orders.region_id', 'orders.shopname', 'orders.delivery_type', 'orders.scheduled_delivery_time_slot_id')
        ->with([
            'shop:id,name,express_time,zone_id',
            'shop.zone:id,name',
            'shop.region:regions.id,regions.name',
            'timeSlot',
            'progress:id,name',
            'openTicket:id,order_id'
        ])
        ->when($clients, function($query, $clients) {
            $query->whereIn('orders.client_id', $clients);
        })
        ->when($shop_name, function($query, $shop_name) {
            $query->whereIn('orders.shopname', $shop_name);
        })
        ->when($order_type, function($query, $order_type) {
            $query->where('delivery_type', $order_type);
        })
        ->when($order_id, function($query, $order_id) {
            $query->where('orders.client_order_id', 'LIKE', $order_id.'%');
        })
        ->when($from_date, function($query) use ($from_date) {
            $query->where(function($query) use ($from_date) {
                $query->where([
                    ['orders.created_at', '>=', date('Y-m-d', strtotime($from_date)).' 00:00:00'],
                    ['orders.delivery_type', '=', Order::DELIVERY_TYPE_FAST],
                ])
                ->orWhere([
                    ['orders.dispatch_at', '>=', date('Y-m-d', strtotime($from_date)).' 00:00:00'],
                    ['orders.delivery_type', '=', Order::DELIVERY_TYPE_SCHEDULE],
                ]);
            });
        })
        ->when($to_date, function($query) use ($to_date) {
            $query->where(function($query) use ($to_date) {
                $query->where([
                    ['orders.created_at', '<=', date('Y-m-d', strtotime($to_date)).' 23:59:59'],
                    ['orders.delivery_type', '=', Order::DELIVERY_TYPE_FAST],
                ])
                ->orWhere([
                    ['orders.dispatch_at', '<=', date('Y-m-d', strtotime($to_date)).' 23:59:59'],
                    ['orders.delivery_type', '=', Order::DELIVERY_TYPE_SCHEDULE],
                ]);
            });
        })
        ->when($status, function($query, $status) {
            if(is_array($status)) {
                $query->whereIn('orders.status_id', $status);
            } else {
                $query->where('orders.status_id', $status);
            }
        })
        ->when($captain, function($query, $captain) {
            $query->where('orders.captain_id', $captain);
        })
        ->withLastLog()
        ->withRegionZone()
        ->withCaptain()
        ->withClient()
        ->belongsTo3pl($this->company_id_3pl)
        ->orderBy('orders.id', 'desc')
        ->paginate(4000, ['*'], 'page', $this->page);
       
        return $orders;
        
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
