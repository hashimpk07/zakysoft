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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CancellationReportExportJob implements ShouldQueue
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
        try {
            $path = 'public/general_exports/';

            $orders = $this->getOrders($this->request);
            if ($this->page == 1) {
                $columns = $this->getColumns();
                $page_count = $orders->lastPage();
                $this->export->update([
                    'page_count' => $page_count,
                ]);
            }

            if ($this->export->status === 'pending') {
                $fileName = $path . Carbon::now()->timestamp . '-' . $this->exportFileName . '-' . $this->export->created_by . '.csv';
                $this->createFile($fileName, implode(',', $columns) . PHP_EOL);
            } else {
                $fileName = $this->export->file;
            }

            if ($this->export->status !== 'processing') {
                $this->export->update([
                    'status' => 'processing',
                    'status_message' => "Job {$this->page} in export processing started",
                    'file' => $fileName,
                    'page_done' => ($this->page - 1),
                ]);
            } elseif ($this->export->status === 'processing') {
                $this->export->update([
                    'status_message' => "Job {$this->page} in export processing started",
                    'file' => $fileName,
                    'page_done' => ($this->page - 1),
                ]);
            }

            $stream = $this->appendToTemp($fileName);

            foreach ($orders as $order) {
                $data = [
                    $order->created_at_date,
                    $order->created_at_time,
                    $order->status . ' By ' . (($order->lastLog && $order->lastLog->canceled_by == '4u') ? 'Dispatcher' : 'Client'),
                    $order->shop_area,
                    $order->shop_zone,
                    $order->client_name,
                    $order->shop_name,
                    $order->client_order_id ?? "N/A",
                    $order->captain_name ?? "N/A",
                    $order->lastLog && $order->lastLog->canceled_by == '4u' ? 'Dispatcher' : 'Client',
                    $order->lastLog && $order->lastLog->canceled_by == '4u' ? $order->lastLog->createdBy->name : 'Client',
                    $order->lastLog->note ?? 'N/A',
                ];

                fputcsv($stream['stream'], $data);
            }

            $path = $this->putData($stream['tempLocalFilePath'], $fileName);
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
                    'page_done' => $this->page,
                ]);

                Mail::to($this->export->email_id)->send(new ExportReportMail($this->export));
                return;
            }

            if ($this->export->status !== 'error') {
                // refresh to get current state of export before using it for next job
                $this->export->refresh();
                dispatch(new static($this->export, $fileName, $nextPage, $this->request));
            }
        } catch (Exception $e) {
            Log::info($e);
            $this->export->update([
                'status' => 'error',
                'status_message' => 'Error',
                'is_ready_for_download' => 0,
                'notify' => 1,
                'page_done' => $this->page,
            ]);
            Mail::to($this->export->email_id)->send(new ExportReportMail($this->export));

            return;
        }
    }

    public function getColumns()
    {
        return [
            'Date',
            'Time',
            'Order Status',
            'City',
            'Zone',
            'Client',
            'Branch',
            'Client Order ID',
            'Captain',
            'Cancelled By',
            'Name',
            'Reason for Cancellation',
        ];
    }

    public function getOrders($request)
    {
        $from_date = $request['from_date'] ?? now()->startOfMonth()->format('Y-m-d');
        $to_date = $request['to_date'] ?? now()->format('Y-m-d');

        $from_date = Carbon::parse($from_date)->setTime(6, 0, 0);
        $to_date = Carbon::parse($to_date)->addDay()->setTime(5, 59, 59);
        return Order::query()
            ->select('orders.id', 'orders.client_order_id', 'orders.created_at', 'order_statuses.name as status')
            ->withShopRegionAndZone()
            ->withShop()
            ->withClient()
            ->withCaptain()
            ->withLastLog('lastLog.createdBy')
            ->leftJoin('order_statuses', 'order_statuses.id', 'orders.status_id')
            ->when($request['client_order_id'] ?? null, function ($query, $client_id) {
                $query->whereLike('client_order_id', $client_id);
            })
            ->whereBetween('orders.created_at', [$from_date, $to_date])
            ->when($request['shopname'] ?? null, function ($query, $shopname) {
                $query->where('orders.shopname', $shopname);
            })
            ->when($request['client'] ?? null, function ($query, $client) {
                $query->where('orders.client_id', $client);
            })
            ->when($request['captain'] ?? null, function ($query, $captain) {
                $query->where('orders.captain_id', $captain);
            })
            ->status([
                OrderStatus::CANCEL,
                OrderStatus::FORYOU_RETURN_ACCEPTED,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
                OrderStatus::CANCEL_REQUEST_ACCEPTED,
            ])
            ->latest('orders.id')
            ->paginate(1000, ['*'], 'page', $this->page);
    }

    public function failed($exception)
    {
        $this->export->update([
            'status' => 'error',
            'status_message' => 'Error',
            'is_ready_for_download' => 0,
            'notify' => 1,
            'page_done' => $this->page,
        ]);

        Mail::to($this->export->email_id)->send(new ExportReportMail($this->export));
    }
}
