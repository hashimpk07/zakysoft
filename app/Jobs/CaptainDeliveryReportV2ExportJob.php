<?php

namespace App\Jobs;

use App\CaptainReport;
use App\GeneralExport;
use App\Mail\ExportReportMail;
use App\Traits\HasFileUpload;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class CaptainDeliveryReportV2ExportJob implements ShouldQueue
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
            $captainDeliveryReports = $this->getReport();

            if ($this->page == 1) {
                $columns = $this->getColumns();
                $page_count = $captainDeliveryReports->lastPage();
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
            foreach ($captainDeliveryReports as $captainDeliveryReport) {
                $receipt = ($captainDeliveryReport->total_received_amount_from_leajlak ?? 0) - ($captainDeliveryReport->total_payed_amount_from_leajlak ?? 0);
                $amount = $captainDeliveryReport->balance ? ($captainDeliveryReport->balance >= 0 ? $captainDeliveryReport->captain->given_custodyamount - $captainDeliveryReport->captain->balance : $captainDeliveryReport->captain->given_custodyamount + abs($captainDeliveryReport->balance)) : 0;
                $csvData = [
                    $captainDeliveryReport->captain->code ?? 'N/A',
                    $captainDeliveryReport->captain->user->name?? "N/A",
                    $captainDeliveryReport->captain->employmentType->name ?? "N/A",
                    $captainDeliveryReport->captain->iqama_number?? "N/A",
                    isset($captainDeliveryReport->captain->nationality->name) ? $captainDeliveryReport->captain->nationality->name : "N/A",
                    $captainDeliveryReport->captain->regions->pluck('quadrant.name')->unique()->join(', '),
                    $captainDeliveryReport->captain->regions->pluck('name')->join(', '),
                    $captainDeliveryReport->captain->date_only_of_joining,
                    $captainDeliveryReport->captain->status?? "N/A",
                    $captainDeliveryReport->attended_orders?? "N/A",
                    number_format($captainDeliveryReport->total_bill_amount, 2),
                    number_format($captainDeliveryReport->store_payments, 2),
                    number_format($captainDeliveryReport->cod, 2),
                    number_format($captainDeliveryReport->credited_to_leajlak, 2),
                    number_format($captainDeliveryReport->captain->given_custodyamount, 2),
                    $receipt >= 0 ? $receipt : "(" . abs($receipt) . ")",
                    $amount >= 0 ? $amount : "(" . abs($amount) . ")",
                    isset($captainDeliveryReport->balance) ? ($captainDeliveryReport->balance >= 0 ? $captainDeliveryReport->balance : "(" . (abs($captainDeliveryReport->balance) . ")")) : 0,
                    $captainDeliveryReport->status(),
                ];
                // Append the new CSV data to the local file
                fputcsv($stream['stream'], $csvData);
            }
            $this->putData($stream['tempLocalFilePath'], $fileName);

            fclose($stream['stream']);

            $nextPageUrl = $captainDeliveryReports->nextPageUrl();
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

        return $captainDeliveryReports = CaptainReport::query()
            ->with('captain.user', 'captain.employmentType', 'captain.nationality', 'captain.regions.quadrant')
            ->when($emp_id, function ($query, $emp_id) {
                $query->whereLike(['captain.code'], $emp_id);
            })
            ->when($captain_id, function ($query, $captain_id) {
                $query->whereHas('captain', function ($query) use ($captain_id) {
                    $query->where('captains.id', $captain_id);
                });
            })

            ->when($name, function ($query, $name) {
                $query->whereLike(['captain.user.name'], $name);
            })
            ->when($iqama, function ($query, $iqama) {
                $query->whereHas('captain', function ($q) use ($iqama) {
                    $q->where('iqama_number', 'LIKE', "%" . $iqama . "%");
                });
            })
            ->when($region, function ($query, $region) {
                $query->whereHas('captain.regions.quadrant', function ($query) use ($region) {
                    $query->where('quadrants.id', $region);
                });
            })
            ->when($area, function ($query, $area) {
                $query->whereHas('captain.regions', function ($query) use ($area) {
                    $query->where('regions.id', $area);
                });
            })
            ->when($job_type, function ($query, $job_type) {
                $query->whereHas('captain', function ($q) use ($job_type) {
                    $q->where('captain_employment_type_id', $job_type);
                });
            })

            ->when($nationality, function ($query, $nationality) {
                $query->whereHas('captain', function ($q) use ($nationality) {
                    $q->where('captains.nationality_id', $nationality);
                });
            })
            ->when($on_duty_from, function ($query, $on_duty_from) {
                $query->whereHas('captain', function ($q) use ($on_duty_from) {
                    $q->where('captains.date_of_joining', '>=', now()->parse($on_duty_from)->format('Y-m-d'));
                });
            })
            ->when($work_status, function ($query, $work_status) {
                $query->whereHas('captain', function ($q) use ($work_status) {
                    $q->where('captains.status', '=', $work_status);
                });
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
