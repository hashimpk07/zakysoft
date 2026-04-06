<?php

namespace App\Jobs;

use App\Client;
use App\ClientShop;
use App\GeneralExport;
use App\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Traits\HasFileUpload;
use App\Mail\ExportReportMail;
use App\Order;
use App\OrderStatus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
class ClientOrderExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HasFileUpload;

    public $timeout = 1200;
    private $export, $exportFileName, $page, $request,$user;
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
        User $user
    ) {
        $this->onQueue('reports');
        $this->export = $export;
        $this->exportFileName = $exportFileName;
        $this->page = $page;
        $this->request = $request;
        $this->user = $user;
    }

    public function handle(): void
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
        
            if($this->export->status === 'pending') {
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
        
            // Open or create the CSV file
            $stream = $this->appendToTemp($fileName);
            
            foreach ($orders as $order) {
                $captain = $order->captain ? $order->captain->user->name : 'Not Assigned';
                $csvData = [
                    'OR#' . sprintf('%03d', $order->id),
                    '#' . $order->client_order_id,
                    isset($order->shop->name)? $order->shop->name : $order->shopname,
                    $order->region_name ?? (isset($order->shop->region->name) ? $order->shop->region->name : ""),
                    $order->zone_name ?? (isset($order->shop->zone->name) ? $order->shop->zone->name : ""),
                    ($order->amount ?? 0) . ' SAR',
                    ($order->delivery_charge ?? 0) . ' SAR',
                    $order->created_at->format('Y-m-d'),
                    $order->progress->name,
                    $captain
                ];
                // Append the new CSV data to the local file
                fputcsv($stream['stream'], $csvData);
            }   

            $path = $this->putData($stream['tempLocalFilePath'],$fileName);
            fclose($stream['stream']);
        
            $nextPageUrl = $orders->nextPageUrl();
            $nextPage = null;
            if (!is_null($nextPageUrl)) {
                $nextPage = explode('=', $nextPageUrl, 2)[1];
            }
        
            if (is_null($nextPage)) {
                // We are done processing
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
                // Refresh to get the current state of export before using it for the next job
                $this->export->refresh();
            
                dispatch(new static($this->export, $fileName, $nextPage, $this->request, $this->user));
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

 
    public function getColumns()
    {
        
        return $columns = [
            "Order ID","Client ID","Shop Name","Area", "Zone", "Amount","Delivery Charge", "Order Date", "Status", "Assigned Captain"
        ];
     
    }

    public function getOrders($request)
    {
        
        $q = isset($request['q']) ? $request['q'] : ''; 
        $fromDate = isset($request['from_date']) ? $request['from_date'] : '';
        $toDate =isset( $request['to_date'] ) ? $request['to_date'] : '' ;
        $shopname = isset($request['shop_name']) ? $request['shop_name'] : NULL;
        $status = isset($request['status']) ? $request['status'] : NULL;
        $user = $this->user;
        $client = Client::where('user_id', $user->employeeClient->first()->user_id)
                ->first();

        if ($user->employeeClient->isNotEmpty()) {
            $shops = $user->clientShops();
        } else {
            $shops = ClientShop::get();
        }

                //This is for filtering based on business day concept (6 AM to 5:59:59 AM next day)
        $from_date = $fromDate ? Carbon::parse($fromDate)->setTime(6, 0, 0)->format('Y-m-d H:i:s') : null;
        $to_date = $toDate ? Carbon::parse($toDate)->addDay()->setTime(5, 59, 59)->format('Y-m-d H:i:s') : null;

            $orders =  Order::where('client_id',$client->id)
            ->with(
                'shop', 
                'shop.zone:id,name',
                'shop.region:regions.id,regions.name',
                'zone', 
                'region', 
                'captain.user', 
                'progress',
            )
            ->with(['openComplaint' => function ($query) {
                $query->withCount('notCaptainSeenMessages');
            }])
            ->when($q, function($query, $q) {
                $query->where('client_order_id','like','%'.$q);
            })
            ->when($from_date, function($query, $from_date) {
                $query->where(function ($query) use ($from_date) {
                    $query->where([
                        ['created_at', '>=', $from_date],
                        ['delivery_type', '=', Order::DELIVERY_TYPE_FAST],
                    ])
                    ->orWhere([
                        ['dispatch_at', '>=', $from_date],
                        ['delivery_type', '=', Order::DELIVERY_TYPE_SCHEDULE],
                    ]);
                });
            })
            ->when($to_date, function($query, $to_date) {
                $query->where(function ($query) use ($to_date) {
                    $query->where([
                        ['created_at', '<=', $to_date],
                        ['delivery_type', '=', Order::DELIVERY_TYPE_FAST],
                    ])
                    ->orWhere([
                        ['dispatch_at', '<=', $to_date],
                        ['delivery_type', '=', Order::DELIVERY_TYPE_SCHEDULE],
                    ]);
                });
            })
            ->when($status, function($query, $statues) {
                $statues = is_string($statues) ? explode(',', $statues) : $statues;
                if($statues && empty(array_diff(is_array($statues) ? $statues : [$statues], [OrderStatus::NEW_ORDER]))) {
                    if(is_array($statues)) {
                        $statues[] = OrderStatus::ORDER_PACKAGE;
                        $statues[] = OrderStatus::ASSIGN_ATTEMPTS;
                    }
                }
    
                if(is_array($statues)) {
                    $query->whereIn('status_id', $statues);
                    return;
                }
                
                $query->where('status_id', $statues);
            })
            ->withRegionZone()
            ->whereIn('shopname', $shops->pluck('id'))
            ->orderBy('id', 'desc')
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
