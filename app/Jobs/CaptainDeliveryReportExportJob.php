<?php

namespace App\Jobs;

use App\Captain;
use App\CaptainOrderPayment;
use App\GeneralExport;
use App\Mail\ExportReportMail;
use App\OrderStatus;
use App\Traits\HasFileUpload;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class CaptainDeliveryReportExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HasFileUpload;

    /**
     * Create a new job instance.
     */
    private $export, $exportFileName, $page, $request;
    public function __construct(
        GeneralExport $export,
        string $exportFileName,
        int $page = 1,
        $request
    ) {
        $this->onQueue('reports');
        $this->export = $export;
        $this->exportFileName = $exportFileName;
        $this->page = $page;
        $this->request = $request;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // $captainDeliveryReports = $this->getReport();
            $captains = $this->getReport();

            if ($this->page == 1) {
                $columns = $this->getColumns();
                $page_count = $captains->lastPage();
                $this->export->update([
                    'page_count' => $page_count,
                ]);
            }

            $path = 'public/general_exports/';
            if ($this->export->status === 'pending') {
                $fileName = $path . Carbon::now()->timestamp . '-' . $this->exportFileName . '-' . $this->export->created_by . '.csv';

                // add the headers only on the first run of this job... on subsequent runs, only append the data
                $fn = $this->createFile($fileName, implode(',', $columns) . PHP_EOL);
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

            // $fileResource =  $this->openFile($fileName, 'a+');
            // rewind($fileResource);
            $stream = $this->appendToTemp($fileName);
            foreach ($captains as $captain) {
                $receipt = ($captain->total_received_amount_from_leajlak ?? 0) - ($captain->total_payed_amount_from_leajlak ?? 0);
                $amount = $captain->orderPayableBalance ? ($captain->orderPayableBalance->balance >= 0 ? $captain->given_custodyamount - $captain->orderPayableBalance->balance : $captain->given_custodyamount + abs($captain->orderPayableBalance->balance)) : 0;
                $csvData = [
                    $captain->code,
                    $captain->user->name,
                    $captain->employmentType->name ?? "N/A",
                    $captain->iqama_number,
                    isset($captain->nationality->name) ? $captain->nationality->name : "N/A",
                    $captain->regions->pluck('quadrant.name')->unique()->join(', '),
                    $captain->regions->pluck('name')->join(', '),
                    $captain->date_only_of_joining,
                    $captain->status,
                    $captain->attended_orders,
                    number_format($captain->total_bill_amount, 2),
                    number_format($captain->store_payments, 2),
                    number_format($captain->cod, 2),
                    number_format($captain->credited_to_leajlak, 2),
                    number_format($captain->given_custodyamount, 2),
                    $receipt >= 0 ? $receipt : "(" . abs($receipt) . ")",
                    $amount >= 0 ? $amount : "(" . abs($amount) . ")",
                    isset($captain->orderPayableBalance->balance) ? ($captain->orderPayableBalance->balance >= 0 ? $captain->orderPayableBalance->balance : "(" . (abs($captain->orderPayableBalance->balance) . ")")) : 0,
                    $captain->orderPayableBalance ? $captain->orderPayableBalance->status() : "N/A",
                ];

                // Append the new CSV data to the local file
                fputcsv($stream['stream'], $csvData);
            }
            $this->putData($stream['tempLocalFilePath'], $fileName);

            fclose($stream['stream']);

            $nextPageUrl = $captains->nextPageUrl();
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
            $this->export->update(attributes: [
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

    public function getReport()
    {

        $emp_id = isset($this->request['employee_id']) ? $this->request['employee_id'] : '';
        $captain_id = isset($this->request['captain']) ? $this->request['captain'] : '';
        $name = isset($this->request['name']) ? $this->request['name'] : '';
        $iqama = isset($this->request['iqama']) ? $this->request['iqama'] : '';
        $region = isset($this->request['region']) ? $this->request['region'] : '';
        $area = isset($this->request['area']) ? $this->request['area'] : '';
        $job_type = isset($this->request['job_type']) ? $this->request['job_type'] : '';
        $nationality = isset($this->request['nationality']) ? $this->request['nationality'] : '';
        $on_duty_from = isset($this->request['on_duty_from']) ? $this->request['on_duty_from'] : '';
        $work_status = isset($this->request['work_status']) ? $this->request['work_status'] : '';
        $payment_status = isset($this->request['payment_status']) ? $this->request['payment_status'] : '';

        return $captains = Captain::query()
            ->with('user', 'employmentType', 'nationality', 'regions.quadrant', 'orderPayableBalance')
            ->withCount(['orders as attended_orders' => function ($query) {
                $query->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED]);
            }])
            ->withSum(["captainOrderPayments as total_received_amount_from_leajlak" => function ($query) {$query->where('type', CaptainOrderPayment::RECEIVING_TYPE);}], 'transferring_amount')
            ->withSum(["captainOrderPayments as total_payed_amount_from_leajlak" => function ($query) {$query->where('type', CaptainOrderPayment::PAYING_TYPE);}], 'transferring_amount')
            ->withSum(["orders as total_bill_amount" => function ($query) {
                $query->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED])
                    ->where('orders.delivery_payment_mode', "Auto");
            }], 'amount')
            ->withSum('shopPayments as store_payments', 'amount')
            ->withSum(["OrderPayment as cod" => function ($query) {
                $query->select(
                    DB::raw('
                        sum(
                            CASE
                                WHEN order_payments.payment_mode = "By Cash" THEN order_payments.given_amount
                                WHEN order_payments.payment_mode = "Both" THEN order_payments.cash
                                ELSE 0
                            END
                        )'
                    )
                );
            }], 'cash')
            ->withSum(["OrderPayment as credited_to_leajlak" => function ($query) {
                $query->select(
                    DB::raw('
                        sum(
                            CASE
                                WHEN order_payments.payment_mode = "By POS" THEN order_payments.given_amount
                                WHEN order_payments.payment_mode = "Both" THEN order_payments.pos_amount
                                ELSE 0
                            END
                        )'
                    )
                );
            }], 'pos_amount')
            ->leftJoin(
                DB::raw('(SELECT MAX(id) AS max_id, captain_id FROM captain_order_payments GROUP BY captain_id) as max_order_payments'),
                'captains.id',
                '=',
                'max_order_payments.captain_id'
            )
            ->leftJoin('captain_order_payments', 'max_order_payments.max_id', '=', 'captain_order_payments.id')
            ->when($emp_id, function ($query, $emp_id) {
                $query->whereLike('code', $emp_id);
            })
            ->when($captain_id, function ($query, $captain_id) {
                $query->where('captains.id', $captain_id);
            })
            ->when($name, function ($query, $name) {
                $query->whereLike(['user.name'], $name);
            })
            ->when($iqama, function ($query, $iqama) {
                $query->where('captains.iqama_number', 'LIKE', $iqama . "%");
            })
            ->when($region, function ($query, $region) {
                $query->whereHas('regions.quadrant', function ($query) use ($region) {
                    $query->where('quadrants.id', $region);
                });
            })
            ->when($area, function ($query, $area) {
                $query->whereHas('regions', function ($query) use ($area) {
                    $query->where('regions.id', $area);
                });
            })
            ->when($job_type, function ($query, $job_type) {
                $query->where('captains.captain_employment_type_id', $job_type);
            })
            ->when($nationality, function ($query, $nationality) {
                $query->where('captains.nationality_id', $nationality);
            })
            ->when($on_duty_from, function ($query, $on_duty_from) {
                $query->where('captains.date_of_joining', '>=', now()->parse($on_duty_from)->format('Y-m-d'));
            })
            ->when($work_status, function ($query, $work_status) {
                $query->where('captains.status', $work_status);
            })
            ->when($payment_status, function ($query, $payment_status) {
                if ($payment_status == 'Receivable') {
                    $query->where('balance', '>', 0);
                }

                if ($payment_status == 'Payable') {
                    $query->where('balance', '<', 0);
                }

                if ($payment_status == 'Tally') {
                    $query->where('balance', '=', 0);
                }
            })
            ->paginate(50, ['*'], 'page', $this->page);
    }

    public function getColumns()
    {

        return $columns = [
            'Emp ID',
            'Captain Name',
            'Job Type',
            'Iqama Number',
            'Nationality',
            'Work Region',
            'Work Area',
            'On Duty From',
            'Work Status',
            'Attended Orders',
            'Total Bill Amount',
            'Store Payments',
            'COD',
            'Credited To leajlak(SPAN)',
            'Given Custody Amount',
            'Reciepts',
            'Current Custody Amount',
            'Without Custody Amount',
            'Payment Status',

        ];
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
