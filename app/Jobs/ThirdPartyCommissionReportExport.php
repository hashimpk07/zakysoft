<?php

namespace App\Jobs;

use App\GeneralExport;
use App\Mail\ExportReportMail;
use App\ThirdPartyLogisticCompany;
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

class ThirdPartyCommissionReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HasFileUpload;

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
            $companies = $this->getReport();

            if ($this->page == 1) {
                $columns = $this->getColumns();
                $page_count = $companies->lastPage();
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
            foreach ($companies as $company) {

                fputcsv(
                    $stream['stream'],
                    [
                        $company->name,
                        $company->cr_number,
                        $company->status,
                        $company->regions->pluck('name')->join(', '),
                        $company->attended_orders ?? 0,
                        number_format($company->total_earnings, 2),
                        number_format($company->paid_commission, 2),
                        number_format($company->lastCommission->balance ?? 0, 2),
                        $company->lastCommission ? ($company->lastCommission->balance > 0 ? 'Payable' : 'Tally') : "N/A"
                    ]
                );

            }
            $path = $this->putData($stream['tempLocalFilePath'], $fileName);
            fclose($stream['stream']);

            $nextPageUrl = $companies->nextPageUrl();
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
        } catch (Exception $e) {
            $this->export->update([
                'status' => 'error',
                'status_message' => $e->getMessage() . ' on page ' . $e->getFile() . ' line ' . $e->getLine(),
                'is_ready_for_download' => 0,
                'notify' => 1,
                'page_done' => $this->page
            ]);
            Mail::to($this->export->email_id)->send(new ExportReportMail($this->export));

            return;
        }
    }

    public function getReport()
    {
        $cr_number = isset($this->request['cr_number']) ? $this->request['cr_number'] : '';
        $company_id = isset($this->request['company_id']) ? $this->request['company_id'] : '';
        $region = isset($this->request['region']) ? $this->request['region'] : '';
        $payment_status = isset($this->request['payment_status']) ? $this->request['payment_status'] : '';

        $commissionsSummary = DB::raw('(SELECT third_party_company_id,
            SUM(total_earned_commission) AS total_earnings,
            COUNT(*) AS attended_orders,
            SUM(settled_amount) AS paid_commission
        FROM third_party_commissions
        GROUP BY third_party_company_id) AS commissions_summary');

        $thirdPartyCompany = ThirdPartyLogisticCompany::query()
            ->with('regions', 'lastCommission')
            ->leftJoin('captains_third_party_logistic', 'third_party_logistic_companies.id', '=', 'captains_third_party_logistic.third_party_logistic_company_id')
            ->leftJoin('third_party_company_commission_payments', 'third_party_logistic_companies.id', '=', 'third_party_company_commission_payments.third_party_company_id')
            ->leftJoin(
                DB::raw('(SELECT MAX(id) AS max_id, third_party_company_id FROM third_party_commissions GROUP BY third_party_company_id) as max_commissions'),
                'third_party_logistic_companies.id',
                '=',
                'max_commissions.third_party_company_id'
            )
            ->leftJoin('third_party_commissions', 'max_commissions.max_id', '=', 'third_party_commissions.id')
            ->leftJoin('captains', 'captains_third_party_logistic.captain_id', '=', 'captains.id')
            ->leftJoin('orders', 'third_party_commissions.order_id', '=', 'orders.id')
            ->leftJoin($commissionsSummary, 'third_party_logistic_companies.id', '=', 'commissions_summary.third_party_company_id')
            ->select(
                'third_party_logistic_companies.id',
                'third_party_logistic_companies.name',
                'third_party_logistic_companies.cr_number',
                'third_party_logistic_companies.status',
                DB::raw('SUM(third_party_company_commission_payments.amount_paid) as paid_amount'),
                DB::raw('0 as payable_amount'),
                'commissions_summary.total_earnings',
                'commissions_summary.attended_orders',
                'commissions_summary.paid_commission',
                DB::raw("'payable' as payment_status")
            )
            ->when($company_id, function ($query, $company_id) {
                $query->where('third_party_logistic_companies.id', '=', $company_id);
            })
            ->when($cr_number, function ($query, $cr_number) {
                $query->where('third_party_logistic_companies.cr_number', '=', $cr_number);
            })
            ->when($region, function ($query, $region) {
                $query->whereHas('regions', function ($query) use ($region) {
                    $query->where('quadrants.id', $region);
                });
            })
            ->when($payment_status, function ($query, $payment_status) {
                if ($payment_status == 'Payable') {
                    $query->where('third_party_commissions.balance', '>', 0);
                }

                if ($payment_status == 'Tally') {
                    $query->where('third_party_commissions.balance', '=', 0);
                }
            })
            ->groupBy('third_party_logistic_companies.id')
            ->paginate(100, ['*'], 'page', $this->page)
            ->withQueryString();

        return $thirdPartyCompany;
    }

    function getColumns()
    {

        return $columns = [
            'Company Name',
            'CR Number',
            'Status',
            'Regions',
            'Attended Orders',
            'Total Earnings',
            'Paid Amount',
            'Payable Amount'
        ];
    }
}
