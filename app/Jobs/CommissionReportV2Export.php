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
use Illuminate\Support\Facades\Mail;

class CommissionReportV2Export implements ShouldQueue
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
        $this->export = $export;
        $this->exportFileName = $exportFileName;
        $this->page = $page;
        $this->request = $request;
        $this->onQueue('reports');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {

            $reportsDatas = $this->getReport();
            if ($this->page == 1) {
                $columns = $this->getColumns();
                $page_count = $reportsDatas->lastPage();
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
            $stream = $this->appendToTemp($fileName);
            foreach ($reportsDatas as $reportsData) {
                fputcsv(
                    $stream['stream'],
                    [
                        $reportsData->captain->code,
                        $reportsData->captain->user->name,
                        $reportsData->captain->employmentType->name ?? "N/A",
                        $reportsData->captain->iqama_number,
                        isset($reportsData->captain->nationality->name) ? $reportsData->captain->nationality->name : "N/A",
                        $reportsData->captain->regions->pluck('quadrant.name')->unique()->join(', '),
                        $reportsData->captain->regions->pluck('name')->join(', '),
                        $reportsData->captain->date_only_of_joining,
                        $reportsData->captain->status,
                        $reportsData->commissioned_attended_orders,
                        number_format($reportsData->avg_commission, 2),
                        number_format($reportsData->total_commission, 2),
                        number_format($reportsData->paid_commission, 2),
                        number_format($reportsData->balance_commission ?? 0, 2),
                        $reportsData->balance_commission > 0 ? 'Payable' : 'Tally',
                        $reportsData->captain->company->name ?? 'N/A',
                    ]
                );

            }
            $path = $this->putData($stream['tempLocalFilePath'], $fileName);
            fclose($stream['stream']);

            $nextPageUrl = $reportsDatas->nextPageUrl();
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
        $third_party_logistic_company = isset($this->request['third_party_logistic_company']) ? $this->request['third_party_logistic_company'] : '';

        return $reportsDatas = CaptainReport::query()
            ->with('captain.user', 'captain.employmentType', 'captain.nationality', 'captain.regions.quadrant', 'captain.company')
        // ->whereDoesntHave('captain.captainThirdParty')
            ->when($emp_id, function ($query, $emp_id) {
                $query->whereLike('captain.code', $emp_id);
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
                $query->whereHas('captain', function ($query) use ($iqama) {
                    $query->where('iqama_number', 'LIKE', $iqama . "%");
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
                $query->whereHas('captain', function ($query) use ($job_type) {
                    $query->where('captain_employment_type_id', $job_type);
                });
            })
            ->when($nationality, function ($query, $nationality) {
                $query->whereHas('captain', function ($query) use ($nationality) {
                    $query->where('nationality_id', $nationality);
                });
            })
            ->when($on_duty_from, function ($query, $on_duty_from) {
                $query->whereHas('captain', function ($query) use ($on_duty_from) {
                    $query->where('date_of_joining', '>=', now()->parse($on_duty_from)->format('Y-m-d'));
                });
            })
            ->when($work_status, function ($query, $work_status) {
                $query->whereHas('captain', function ($query) use ($work_status) {
                    $query->where('status', '=', $work_status);
                });
            })
            ->when($third_party_logistic_company, function ($query, $third_party_logistic_company) {
                $query->whereHas('company', function ($query) use ($third_party_logistic_company) {
                    $query->where('third_party_logistic_companies.id', $third_party_logistic_company);
                });
            })
            ->when($payment_status, function ($query, $payment_status) {
                if ($payment_status == 'Payable') {
                    $query->where('balance_commission', '>', 0);
                }

                if ($payment_status == 'Tally') {
                    $query->where('balance_commission', '=', 0);
                }

            })
            ->paginate(100, ['*'], 'page', $this->page)->withQueryString();

    }

    public function getColumns()
    {

        return [
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
            'Com/Order',
            'Total Com',
            'Paid Com',
            'Payable Com',
            'Payment Status',
            '3PL Company',
        ];
    }
}
