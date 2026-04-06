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
use Illuminate\Support\Facades\Mail;

class ClientSalesReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HasFileUpload;

    public $timeout = 1200;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        private GeneralExport $export,
        private string $exportFileName,
        private int $page = 1,
        private $request
    ) {
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

            $orders = $this->getOrders();
            if ($this->page == 1) {
                $columns = $this->getColumns();
                $page_count = $orders->lastPage();
                $this->export->update([
                    'page_count' => $page_count,
                ]);
            }

            if ($this->export->status === 'pending') {
                $fileName = $path . Carbon::now()->timestamp . '-' . $this->exportFileName . '-' . $this->export->created_by . '.csv';

                // add the headers only on the first run of this job... on subsequent runs, only append the data
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

            foreach ($orders as $sale) {
                fputcsv(
                    $stream['stream'],
                    [
                        $sale->created_at_date,
                        $sale->created_at_time,
                        $sale->id,
                        $sale->client_name,
                        $sale->shop_name,
                        $sale->client_order_id,
                        $sale->captain->company->name ?? ($sale->captain->employmentType->name ?? ""),
                        $sale->captain->user->name ?? '',
                        $sale->captain->iqama_number ?? '',
                        $sale->captain && $sale->captain->regions ? $sale->captain->regions->pluck('quadrant.name')->unique()->join(', ') : "",
                        $sale->captain && $sale->captain->regions ? $sale->captain->regions->pluck('name')->unique()->join(', ') : "",
                        number_format($sale->shop_to_delivery_km, 2),
                        $sale->progress->name,
                        $sale->formatted_delivery_date,
                        number_format($sale->orderDeliveryCharge->basic_delivery_charge ?? 0, 2),
                        number_format($sale->orderDeliveryCharge->additional_km ?? 0, 2),
                        number_format($sale->orderDeliveryCharge->additional_km_earning ?? 0, 2),
                        number_format($sale->orderDeliveryCharge->total_earnings ?? 0, 2),
                        number_format($sale->orderDeliveryCharge->vat ?? 0, 2),
                        $sale->orderDeliveryCharge ? number_format($sale->orderDeliveryCharge->vat + $sale->orderDeliveryCharge->total_earnings, 2) : '0.00',
                    ]
                );
            }
            $this->putData($stream['tempLocalFilePath'], $fileName);

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
            $this->export->update([
                'status' => 'error',
                'status_message' => $e->getMessage() . ' at line ' . $e->getLine() . ' in ' . $e->getFile() . ' on ' . $e->getTrace()[0]['function'] . ' function.',
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
            'Order Date',
            'Order Time',
            'Order Ref',
            'Client Name',
            "Shop Name",
            "AWB",
            'Employment Type',
            'Captain Name',
            'Iqama No',
            'Work Region',
            'Work Area',
            'Distance B/W O2D',
            'Order Status',
            'End Date',
            'B.D. Price',
            'Extra Km',
            'E.KM Price',
            'T.Delivery Price',
            'Vat Amount',
            'D.Price + Vat',
        ];
    }

    public function getOrders()
    {
        $request = $this->request;
        $date_from = $request['from_date'] ?? now()->startOfMonth()->format('Y-m-d');
        $date_to = $request['to_date'] ?? now()->format('Y-m-d');
        // if (($date_from) || (isset($request['order_time_from']) && $request['order_time_from'] != null)) {
        //     $date_from = now()->parse($date_from . ' ' . ($request['order_time_from'] ?? '06:00:00'));
        // }

        // if (($date_to) || (isset($request['order_time_to']) && $request['order_time_to'] != null)) {
        //     $date_to = now()->addDay()->parse($date_to . ' ' . ($request['order_time_to'] ?? '05:59:59'));
        // }

       
        if ($date_from || (isset($request['order_time_from']) && $request['order_time_from'] != null)) {
            $date_from = Carbon::parse($date_from . ' ' . ($request['order_time_from'] ?? '06:00:00'));
        }

        if ($date_to || (isset($request['order_time_to']) && $request['order_time_to'] != null)) {
            $date_to = Carbon::parse($date_to . ' ' . ($request['order_time_to'] ?? '05:59:59'))->addDay();
        }

        return Order::query()
            ->select('id', 'client_id', 'status_id', 'created_at', 'delivery_date', 'shop_to_delivery_km', 'client_order_id', 'captain_id')
            ->withClient()
            ->withShop()
            ->with('progress:id,name', 'orderDeliveryCharge')
            ->with('captain:id,user_id,iqama_no,captain_employment_type_id', 'captain.user:id,name')
            ->with('captain.employmentType')
            ->with('captain.regions.quadrant')
            ->when($request['client'] ?? null, function ($query, $client) {
                return $query->where('client_id', $client);
            })
            ->when($request['shopname'] ?? null, function ($query, $shopname) {
                return $query->where('shopname', $shopname);
            })
            ->when($request['captain'] ?? null, function ($query, $captain) {
                return $query->where('captain_id', $captain);
            })
            ->when($request['orderID'] ?? null, function ($query, $client_order_id) {
                return $query->whereLike('client_order_id', $client_order_id);
            })
            ->withinDateRange($date_from, $date_to, 'orders.delivery_date')
            ->whereIn('status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::CANCEL])
            ->orderBy('delivery_date', 'desc')->paginate(4000, ['*'], 'page', $this->page);
    }

    public function failed(Exception $exception)
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
