<?php

namespace App\Jobs;

use App\Captain;
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
use Illuminate\Support\Facades\Storage;

class CaptainPerformanceExportJob implements ShouldQueue
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
            $performance_reports = $this->getReport();

            if ($this->page == 1) {
                $columns = $this->getColumns();
                $page_count = $performance_reports->lastPage();
                $this->export->update([
                    'page_count' => $page_count,
                ]);
            }

            $filesystemAdapter = Storage::disk('public');
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

            foreach ($performance_reports as $performance_report) {
                fputcsv(
                    $stream['stream'],
                    [
                        $performance_report->full_name,
                        $performance_report->iqama_number,
                        $performance_report->code,
                        $performance_report->company->name ?? ($performance_report->employmentType->name ?? "N/A"),
                        $performance_report->regions->pluck('quadrant.name')->unique()->join(', '),
                        $performance_report->no_of_days_worked,
                        $performance_report->no_of_productive_days_worked,
                        secondsToTime($performance_report->total_work_time_in_seconds),
                        $performance_report->no_of_days_worked ? secondsToTime($performance_report->total_work_time_in_seconds / $performance_report->no_of_days_worked) : '00:00:00',
                        number_format($performance_report->no_of_orders_sent),
                        number_format($performance_report->no_of_trying_to_accept_orders),
                        number_format($performance_report->no_of_no_response_requests),
                        number_format($performance_report->no_of_declined_requests),
                        number_format($performance_report->no_of_orders_accepted),
                        number_format($performance_report->no_of_orders_sent ? ( ( $performance_report->no_of_orders_accepted + $performance_report->no_of_trying_to_accept_orders) / $performance_report->no_of_orders_sent )* 100 : 0, 2),
                        number_format($performance_report->no_of_completed_orders),
                        number_format($performance_report->no_of_returned_orders),
                        number_format($performance_report->no_of_canceled_orders),
                        number_format($performance_report->no_of_orders_accepted ? ($performance_report->no_of_completed_orders / $performance_report->no_of_orders_accepted) * 100 : 0, 2),
                        number_format($performance_report->no_of_orders_accepted - ($performance_report->no_of_completed_orders + $performance_report->no_of_returned_orders + $performance_report->no_of_canceled_orders)),
                    ]
                );

            }

            $path = $this->putData($stream['tempLocalFilePath'], $fileName);
            fclose($stream['stream']);

            $nextPageUrl = $performance_reports->nextPageUrl();
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
        $from_date = isset($this->request['from_date']) ? Carbon::parse($this->request['from_date'])->format('Y-m-d 00:00:00') : Carbon::now()->startOfMonth("Y-m-d 00:00:00");
        $to_date = isset($this->request['to_date']) ? Carbon::parse($this->request['to_date'])->format('Y-m-d 23:59:59') : Carbon::now()->format('Y-m-d 23:59:59');
        $region = isset($this->request['region']) ? $this->request['region'] : '';
        $captain = isset($this->request['captain']) ? $this->request['captain'] : '';
        $client = isset($this->request['client']) ? $this->request['client'] : '';
        $q = isset($this->request['q']) ? $this->request['q'] : '';
        $sort_by = isset($this->request['sort_by']) ? $this->request['sort_by'] : '';
        $sort_order = isset($this->request['sort_order']) ? $this->request['sort_order'] : 'asc';
        $employment_type = isset($this->request['employment_type']) ? $this->request['employment_type'] : '';
        $companies = isset($this->request['companies']) ? $this->request['companies'] : false;
        $quadrants = isset($this->request['quadrants']) ? $this->request['quadrants'] : false;

        return Captain::with('regions.quadrant', 'employmentType', 'company')
            ->select(
                'captains.id',
                DB::raw('CONCAT(captains.firstname, " ", captains.lastname) as full_name'),
                'captains.code',
                'captains.job_type',
                'captains.iqama_number',
                'captain_employment_type_id'
            )
            ->addSelect(
                DB::raw('(select SUM(TIMESTAMPDIFF(SECOND,shift_statuses.shift_start, IFNULL(shift_statuses.shift_end, now())))
                    FROM shift_statuses
                    WHERE
                        shift_statuses.captain_id = captains.id' .
                    ($from_date ? ' AND shift_statuses.created_at >= "' . now()->parse($from_date)->format('Y-m-d 00:00:00') . '"' : '') .
                    ($to_date ? ' AND shift_statuses.created_at <= "' . now()->parse($to_date)->format('Y-m-d 23:59:59') . '"' : '') .
                    ' GROUP BY shift_statuses.captain_id
                ) as total_work_time_in_seconds')
            )
            ->addSelect(
                DB::raw('(SELECT COUNT(*) FROM (SELECT count(*)
                            FROM shift_statuses
                            WHERE
                                shift_statuses.captain_id = captains.id' .
                    ($from_date ? ' AND shift_statuses.created_at >= "' . now()->parse($from_date)->format('Y-m-d 00:00:00') . '"' : '') .
                    ($to_date ? ' AND shift_statuses.created_at <= "' . now()->parse($to_date)->format('Y-m-d 23:59:59') . '"' : '') .
                    ' GROUP BY CAST(created_at AS DATE)
                        ) as no_of_shifts_days_worked) as no_of_days_worked')
            )
            ->addSelect(
                DB::raw('(SELECT COUNT(*) FROM (SELECT count(*)
                            FROM shift_statuses
                            LEFT JOIN orders ON orders.captain_id = shift_statuses.captain_id
                            WHERE
                                shift_statuses.captain_id = captains.id AND
                                date(orders.delivery_date) = date(shift_statuses.created_at)' .
                    ($from_date ? ' AND shift_statuses.created_at >= "' . now()->parse($from_date)->format('Y-m-d 00:00:00') . '"' : '') .
                    ($to_date ? ' AND shift_statuses.created_at <= "' . now()->parse($to_date)->format('Y-m-d 23:59:59') . '"' : '') .

                    ' GROUP BY date(shift_statuses.created_at)
                        ) as no_of_shifts_days_worked) as no_of_productive_days_worked')
            )
            ->addSelect(
                DB::raw('(SELECT COUNT(*)
                        FROM (SELECT  COUNT(*)
                            FROM
                                package_delivery_requests
                                LEFT JOIN packages ON packages.id = package_delivery_requests.package_id
                                LEFT JOIN package_orders ON package_orders.package_id = packages.id
                                LEFT JOIN orders ON orders.id = package_orders.order_id
                            WHERE
                                package_delivery_requests.captain_id = captains.id AND orders.id IS NOT NULL' .
                    ($from_date ? ' AND package_delivery_requests.sended_at >= "' . now()->parse($from_date)->format('Y-m-d 00:00:00') . '"' : '') .
                    ($to_date ? ' AND package_delivery_requests.sended_at <= "' . now()->parse($to_date)->format('Y-m-d 23:59:59') . '"' : '') .
                    ($client ? ' AND orders.client_id = ' . $client : '') .
                    ' GROUP BY orders.id
                        ) as no_of_times_orders_sent
                    ) as no_of_orders_sent')
            )
            ->addSelect(
                DB::raw('(SELECT COUNT(*)
                    FROM (SELECT  COUNT(*)
                        FROM
                            packages
                            LEFT JOIN package_orders ON package_orders.package_id = packages.id
                            LEFT JOIN orders ON orders.id = package_orders.order_id
                        WHERE
                        packages.captain_id = captains.id AND orders.id IS NOT NULL' .
                    ($from_date ? ' AND packages.created_at >= "' . now()->parse($from_date)->format('Y-m-d 00:00:00') . '"' : '') .
                    ($to_date ? ' AND packages.created_at <= "' . now()->parse($to_date)->format('Y-m-d 23:59:59') . '"' : '') .
                    ($client ? ' AND orders.client_id = ' . $client : '') .
                    ' GROUP BY orders.id
                    ) as no_of_times_orders_accepted
                ) as no_of_orders_accepted')
            )
            ->addSelect(
                DB::raw('(SELECT  COUNT(*)
                        FROM
                            orders
                        WHERE
                            orders.captain_id = captains.id AND
                            orders.status_id = ' . OrderStatus::DELIVERED .
                    ($from_date ? ' AND orders.created_at >= "' . now()->parse($from_date)->format('Y-m-d 00:00:00') . '"' : '') .
                    ($to_date ? ' AND orders.created_at <= "' . now()->parse($to_date)->format('Y-m-d 23:59:59') . '"' : '') .
                    ($client ? ' AND orders.client_id = ' . $client : '') .
                    ') as no_of_completed_orders')
            )
            ->addSelect(
                DB::raw('(SELECT  COUNT(*)
                            FROM
                                orders
                            WHERE
                                orders.captain_id = captains.id AND
                                orders.status_id in (' . OrderStatus::CLIENT_RETURN_ACCEPTED . ',' . OrderStatus::RETURN_TO_CLIENT . ',' . OrderStatus::FORYOU_RETURN_ACCEPTED . ',' . OrderStatus::CLIENT_RETURN_DECLINE . ')' .
                    ($from_date ? ' AND orders.created_at >= "' . now()->parse($from_date)->format('Y-m-d 00:00:00') . '"' : '') .
                    ($to_date ? ' AND orders.created_at <= "' . now()->parse($to_date)->format('Y-m-d 23:59:59') . '"' : '') .
                    ($client ? ' AND orders.client_id = ' . $client : '') .
                    ') as no_of_returned_orders')
            )
            ->addSelect(
                DB::raw('(SELECT  COUNT(*)
                        FROM
                            orders
                        WHERE
                            orders.captain_id = captains.id AND
                            orders.status_id in (' . OrderStatus::CANCEL_REQUEST_ACCEPTED . ',' . OrderStatus::CANCEL . ')' .
                    ($from_date ? ' AND orders.created_at >= "' . now()->parse($from_date)->format('Y-m-d 00:00:00') . '"' : '') .
                    ($to_date ? ' AND orders.created_at <= "' . now()->parse($to_date)->format('Y-m-d 23:59:59') . '"' : '') .
                    ($client ? ' AND orders.client_id = ' . $client : '') .
                    ') as no_of_canceled_orders')
            )

            ->addSelect(
                DB::raw('
                    (SELECT
                        SUM(total_missed_orders_subquery.order_count)
                    FROM (
                        SELECT
                            COUNT(*) as order_count
                        FROM
                            package_delivery_requests
                            LEFT JOIN packages ON packages.id = package_delivery_requests.package_id
                            LEFT JOIN package_orders ON package_orders.package_id = packages.id
                            LEFT JOIN orders ON orders.id = package_orders.order_id
                        WHERE
                            package_delivery_requests.captain_id = captains.id
                            AND orders.captain_id <> captains.id
                            AND package_delivery_requests.attempted_at IS NOT NULL' .
                    ($from_date ? ' AND package_delivery_requests.sended_at >= "' . now()->parse($from_date)->format('Y-m-d 00:00:00') . '"' : '') .
                    ($to_date ? ' AND package_delivery_requests.sended_at <= "' . now()->parse($to_date)->format('Y-m-d 23:59:59') . '"' : '') .
                    ($client ? ' AND orders.client_id = ' . $client : '') .
                    'GROUP BY orders.id
                    ) total_missed_orders_subquery) as total_missed_orders
                ')
            )
            ->addSelect(
                DB::raw('
                    (SELECT
                        SUM(no_of_no_response_requests_subquery.order_count)
                    FROM (
                        SELECT
                            COUNT(*) as order_count
                        FROM
                            package_delivery_requests
                            LEFT JOIN packages ON packages.id = package_delivery_requests.package_id
                            LEFT JOIN package_orders ON package_orders.package_id = packages.id
                            LEFT JOIN orders ON orders.id = package_orders.order_id
                        WHERE
                            orders.captain_id <> captains.id
                            AND package_delivery_requests.captain_id = captains.id
                            AND package_delivery_requests.attempted_at IS NULL
                            AND packages.captain_id IS NOT NULL
                            AND TIMESTAMPDIFF(MINUTE, package_delivery_requests.sended_at, NOW()) >= 3' .
                    ($from_date ? ' AND package_delivery_requests.sended_at >= "' . now()->parse($from_date)->format('Y-m-d 00:00:00') . '"' : '') .
                    ($to_date ? ' AND package_delivery_requests.sended_at <= "' . now()->parse($to_date)->format('Y-m-d 23:59:59') . '"' : '') .
                    ($client ? ' AND orders.client_id = ' . $client : '') .
                    'GROUP BY orders.id
                    ) no_of_no_response_requests_subquery) as no_of_no_response_requests
                ')
            )
            ->addSelect(
                DB::raw('
                    (SELECT
                        SUM(no_of_declined_requests_subquery.order_count)
                    FROM (
                        SELECT
                            COUNT(*) as order_count
                        FROM
                            package_delivery_requests
                            LEFT JOIN packages ON packages.id = package_delivery_requests.package_id
                            LEFT JOIN package_orders ON package_orders.package_id = packages.id
                            LEFT JOIN orders ON orders.id = package_orders.order_id
                        WHERE
                            package_delivery_requests.captain_id = captains.id
                            AND orders.captain_id <> captains.id
                            AND package_delivery_requests.declined_at IS NOT NULL' .
                    ($from_date ? ' AND package_delivery_requests.sended_at >= "' . now()->parse($from_date)->format('Y-m-d 00:00:00') . '"' : '') .
                    ($to_date ? ' AND package_delivery_requests.sended_at <= "' . now()->parse($to_date)->format('Y-m-d 23:59:59') . '"' : '') .
                    ($client ? ' AND orders.client_id = ' . $client : '') .
                    'GROUP BY orders.id
                    ) no_of_declined_requests_subquery) as no_of_declined_requests
            '),
            )
            ->when($captain, function ($query, $captain) {
                return $query->whereIn('captains.id', $captain);
            })
            ->when($quadrants, function ($query, $quadrants) {
                return $query->whereHas('regions.quadrant', function ($query) use ($quadrants) {
                    $query->whereIn('quadrants.id', $quadrants);
                });
            })
            ->when($region, function ($query, $region) {
                return $query->whereHas('regions', function ($query) use ($region) {
                    $query->whereIn('regions.id', $region);
                });
            })
            ->when($employment_type, function ($query, $employment_types) {
                return $query->whereIn('captain_employment_type_id', $employment_types);
            })
            ->whereIn('status', [Captain::STATUS_ACTIVE, Captain::STATUS_BANNED, Captain::STATUS_INACTIVE])
            ->when($q, function ($query, $q) {
                return $query->whereLike([
                    'user.name',
                    'code',
                    'user.email',
                    'iqama_number',
                ], $q);
            })
            ->when($sort_by, function ($query, $by) use ($sort_order) {
                $order = strtolower($sort_order) == 'asc' ? 'asc' : 'desc';

                if ($by == 'acceptance_rate') {
                    $query->orderByRaw('no_of_orders_accepted / no_of_orders_sent ' . $order);
                }

                if ($by == 'success_rate') {
                    $query->orderByRaw('no_of_completed_orders / no_of_orders_sent ' . $order);
                }
            })
            ->when($companies, function ($query, $companies) {
                return $query->whereHas('company', function ($query) use ($companies) {
                    $query->whereIn('third_party_logistic_companies.id', $companies);
                });
            })
            ->orderBy('captains.id')
            ->paginate(100, ['*'], 'page', $this->page)
            ->withQueryString();
    }

    public function getColumns()
    {

        return [
            'Captain',
            'Iqama No',
            'Employee Id',
            'Employee Type',
            'Work Location',
            'Working Days',
            'Productive Days',
            'Online Hours',
            'Avg. OH',
            'Received Orders',
            'Try to accept orders',
            'Rejected orders',
            'Expired orders',
            'Accepted Orders',
            'Acceptance Rate(%)',
            'Delivered Orders',
            'Returned Orders',
            'Canceled Orders',
            'Success Rate(%)',
            'Diff. in Orders',
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
