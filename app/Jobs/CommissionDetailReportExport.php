<?php

namespace App\Jobs;

use App\GeneralExport;
use App\Mail\ExportReportMail;
use App\Order;
use App\OrderStatus;
use App\Traits\HasFileUpload;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class CommissionDetailReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HasFileUpload;

    /**
     * Create a new job instance.
     *
     * @return void
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
     *
     * @return void
     */
    public function handle()
    {
        try {
            $orders = $this->getReport();

            if ($this->page == 1) {
                $columns = $this->getColumns();
                $page_count = $orders->lastPage();
                $this->export->update([
                    'page_count' => $page_count,
                ]);
            }

            $filesystemAdapter = Storage::disk('public');
            $path = 'public/general_exports/';
            if ($this->export->status === 'pending') {
                $fileName = $path . now()->timestamp . '-' . $this->exportFileName . '-' . $this->export->created_by . '.csv';

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

            // foreach ($orders as $order) {
            //     fputcsv(
            //         $stream['stream'],
            //         [
            //             $order->created_at ? now()->parse($order->created_at)->format('d/m/Y') : 'N/A',
            //             $order->captain && $order->captain->user ? $order->captain->user->name : "",
            //             $order->captain && isset($order->captain->iqama_number) ? $order->captain->iqama_number : "",
            //             $order->captain && $order->captain->company ? $order->captain->company->name :
            //             ($order->captain && $order->captain->employmentType ? $order->captain->employmentType->name : ""),
            //             $order->captain && $order->captain->regions ? $order->captain->regions->pluck('name')->unique()->join(', ') : '',
            //             $order->captain && $order->captain->regions ? $order->captain->regions->pluck('quadrant.name')->unique()->join(', ') : '',
            //             $order->client && $order->client->user ? $order->client->user->name : '',
            //             $order->shop ? $order->shop->name : '',
            //             $order->client_order_id ?? '',
            //             $order->delivery_date ? now()->parse($order->delivery_date)->format('d/m/Y') : 'N/A',
            //             $order->progress ? $order->progress->name : '',
            //             $order->shop_to_delivery_km ?? 0,
            //             $order->captainCommission ? $order->captainCommission->basic_delivery_earnings : 0,
            //             $order->captainCommission ? $order->captainCommission->additional_km_earning : 0,
            //             $order->captainCommission ? $order->captainCommission->commission : 'N/A'
            //         ]
            //     );

            // }
            foreach ($orders as $order) {
                $createdAt = $order->created_at ? now()->parse($order->created_at)->format('d/m/Y') : 'N/A';

                $captain = optional($order->captain);
                $captainUser = optional($captain->user);
                $captainCompany = optional($captain->company);
                $captainEmployment = optional($captain->employmentType);
                $captainRegions = $captain->regions ?? collect();
                $regions = $captainRegions->pluck('name')->filter()->unique()->join(', ');
                $quadrants = $captainRegions->pluck('quadrant.name')->filter()->unique()->join(', ');

                $clientUser = optional(optional($order->client)->user);
                $shop = optional($order->shop);
                $progress = optional($order->progress);
                $commission = optional($order->captainCommission);

                $deliveryDate = $order->delivery_date ? now()->parse($order->delivery_date)->format('d/m/Y') : 'N/A';

                fputcsv(
                    $stream['stream'],
                    [
                        $createdAt,
                        $captainUser->name ?? '',
                        $captain->iqama_number ?? '',
                        $captainCompany->name ?? $captainEmployment->name ?? '',
                        $regions,
                        $quadrants,
                        $clientUser->name ?? '',
                        $shop->name ?? '',
                        $order->client_order_id ?? '',
                        $deliveryDate,
                        $progress->name ?? '',
                        $order->shop_to_delivery_km ?? 0,
                        $commission->basic_delivery_earnings ?? 0,
                        $commission->additional_km_earning ?? 0,
                        $commission->commission ?? 'N/A',
                    ]
                );
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
                'status_message' => $e->getMessage() . ' at line ' . $e->getLine(),
                'is_ready_for_download' => 0,
                'notify' => 1,
                'page_done' => $this->page
            ]);
            Mail::to($this->export->email_id)->send(new ExportReportMail($this->export));
            throw $e;
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

        return Order::query()
            ->with(
                'captain.user',
                'client.user',
                'shop',
                'progress',
                'payment',
                'shopPayment',
                'captainCommission.settledBy',
                'captainCommission.attachments',
                "captain.company",
                "captain.employmentType",
                "captain.regions.quadrant",
            )
            ->orderBy('delivery_date', 'desc')
            ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED])
            ->has('captainCommission')
            ->when($this->request['from_date'], function ($query, $from_date) {
                // Convert to business day start (6:00 AM)
                $businessDayStart = now()->parse($from_date)->setTime(6, 0, 0);
                $query->where('orders.delivery_date', '>=', $businessDayStart->format('Y-m-d H:i:s'));
            })
            ->when($this->request['to_date'], function ($query, $to_date) {
                // Convert to business day end (5:59:59 AM next day)
                $businessDayEnd = now()->parse($to_date)->addDay()->setTime(5, 59, 59);
                $query->where('orders.delivery_date', '<=', $businessDayEnd->format('Y-m-d H:i:s'));
            })
            // ->when($this->request['from_date'], function ($query, $from_date) {
            //     $query->where('orders.delivery_date', '>=', now()->parse($from_date)->format('Y-m-d') . ' 00:00:00');
            // })
            // ->when($this->request['to_date'], function ($query, $to_date) {
            //     $query->where('orders.delivery_date', '<=', now()->parse($to_date)->format('Y-m-d') . ' 23:59:59');
            // })
            ->when($emp_id, function ($query, $emp_id) {
                $query->whereLike('captain.code', $emp_id);
            })
            ->when($third_party_logistic_company, function ($query, $third_party_logistic_company) {
                $query->whereHas('captain.company', function ($query) use ($third_party_logistic_company) {
                    $query->where('third_party_logistic_companies.id', $third_party_logistic_company);
                });
            })
            ->when($captain_id, function ($query, $captain_id) {
                 $query->whereHas('captain', function ($query) use ($captain_id) {
                    $query->where('captains.id', $captain_id);
                    // $query->where('captains.iqama_number', 'LIKE', $iqama . "%");
                });
                
            })
            ->when($name, function ($query, $name) {
                $query->whereLike(['captain.user.name'], $name);
            })
            ->when($iqama, function ($query, $iqama) {
                $query->whereHas('captain', function ($query) use ($iqama) {
                    $query->where('captains.iqama_number', 'LIKE', $iqama . "%");
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
                    $query->where('captains.captain_employment_type_id', $job_type);
                });
            })
            ->when($nationality, function ($query, $nationality) {
                $query->whereHas('captain', function ($query) use ($nationality) {
                    $query->where('captains.nationality_id', $nationality);
                });
            })
            ->when($on_duty_from, function ($query, $on_duty_from) {
                $query->whereHas('captain', function ($query) use ($on_duty_from) {
                    $query->where('captains.date_of_joining', '>=', now()->parse($on_duty_from)->format('Y-m-d'));
                });
            })
            ->when($work_status, function ($query, $work_status) {
                $query->whereHas('captain', function ($query) use ($work_status) {
                    $query->where('captains.status', '=', $work_status);
                });
            })
            ->when($payment_status, function ($query, $payment_status) {
                $query->leftJoin(
                    DB::raw('(SELECT MAX(id) AS max_id, captain_id FROM captain_commissions GROUP BY captain_id) as max_commissions'),
                    'orders.captain_id',
                    '=',
                    'max_commissions.captain_id'
                )
                    ->leftJoin('captain_commissions', 'max_commissions.max_id', '=', 'captain_commissions.id');

                if ($payment_status == 'Payable') {
                    $query->where('balance', '>', 0);
                }

                if ($payment_status == 'Tally') {
                    $query->where('balance', '=', 0);
                }
            })
            ->paginate(10000, ['*'], 'page', $this->page);
    }

    function getColumns()
    {

        return [
            'Order Date',
            'Captain',
            'Iqama Number',
            'Employment Type',
            'Work Area',
            'Work Region',
            'Client',
            'Shop',
            'AWB',
            'Delivered Date',
            'Order Status',
            'KM',
            'B.D. Earing',
            'E.KM. Earing',
            'T. Earing'
        ];
    }
}
