<?php

namespace App\Jobs;

use App\Captain;
use App\CaptainSalaryPaymentDate;
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

class ConfirmPaymentExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HasFileUpload;

     /**
     * The name of the queue the job should be sent to.
     *
     * @var string|null
     */

    private $export,$exportFileName,$page,$request;
    public function __construct(
         GeneralExport $export,
         string $exportFileName,
         int $page = 1,
         $request
    )
    {
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
        
        try{
          $captains = $this->getReport();
            
            if($this->page == 1){
                    $columns = $this->getColumns();   
                    $page_count = $captains->lastPage();
                    $this->export->update([
                        'page_count' => $page_count,
                    ]);
                }

                $filesystemAdapter = Storage::disk('public');
                $path = 'public/general_exports/';
                if($this->export->status === 'pending') {
                    $fileName =  $path. Carbon::now()->timestamp . '-' . $this->exportFileName . '-' . $this->export->created_by . '.csv';

                    // add the headers only on the first run of this job... on subsequent runs, only append the data
                    $fn = $this->createFile($fileName,implode(',', $columns). PHP_EOL);
                } else {
                    $fileName = $this->export->file;
                }

                if($this->export->status !== 'processing') {
                    $this->export->update([
                        'status' => 'processing',
                        'status_message' => "Job {$this->page} in export processing started",
                        'file'=>$fileName,
                        'page_done'=>($this->page-1)
                    ]);
                } elseif($this->export->status === 'processing') {
                    $this->export->update([
                        'status_message' => "Job {$this->page} in export processing started",
                        'file'=>$fileName,
                        'page_done'=>($this->page-1)
                    ]);
                }

                $stream = $this->appendToTemp($fileName);
                $from_date = isset($this->request['from_date']) ? Carbon::parse($this->request['from_date'])->format('Y-m-d 00:00:00') : '';
                $to_date = isset($this->request['to_date']) ? Carbon::parse($this->request['to_date'])->format('Y-m-d 23:59:59') : '';

                foreach ($captains as $captain) {
                    $balance = $captain->lastCommission->balance??0;
                    if( $from_date||$to_date)
                    {
                        $filter_commission = ($captain->total_filter_commission) ? $captain->total_filter_commission : 0;
                        $balance = ($balance <= $filter_commission) ? $balance : $filter_commission ;
                        
                    }
                    // For captain salary calculate
                    $salary_to_pay = 0;
                    $per_day_salary = 0;
                    $total_salary = 0;
                    $worked_days = 0;
                
                    if(isset($this->request['salary_from_date']) && isset($this->request['salary_from_date'])){
                        $sal_from_date = Carbon::parse($this->request['salary_from_date']);
                        $sal_to_date   = Carbon::parse($this->request['salary_to_date']);
                        $date_of_joining = Carbon::parse($captain->date_of_joining);
                        $total_days =   $sal_from_date->daysInMonth;
                        $salaryPaidCount =  CaptainSalaryPaymentDate::join('captain_salary_payments','captain_salary_payments.id','captain_salary_payment_dates.salary_payment_id')
                        ->where('captain_salary_payments.captain_id',$captain->id)
                        ->whereBetween('paid_on_date',[$sal_from_date,$sal_to_date])
                        ->count();
                    
                        if($date_of_joining > $sal_to_date)
                            $worked_days = 0;
                        elseif ($date_of_joining->between($sal_from_date, $sal_to_date)) {
                            $worked_days = $date_of_joining->diffInDays($sal_to_date)+1;
                        }
                        elseif ($date_of_joining < $sal_from_date) {
                            $worked_days = $sal_from_date->diffInDays($sal_to_date)+1;
                    }
                    
                    if($salaryPaidCount > 0 && $worked_days > 0)
                        $worked_days  = $worked_days - $salaryPaidCount;
                    
                        $monthly_salary =  $captain->monthly_salary??0;
                        $per_day_salary = 0;
                        if($monthly_salary>0 && $worked_days > 0){
                            $per_day_salary = round($monthly_salary / $total_days,2);
                            $total_salary =  round($per_day_salary * $worked_days);
                        }
                    }

                    $total_earnings = $balance + $total_salary;

                        // include Salary 
                    if(isset($this->request['salary_from_date'])){
                        fputcsv(
                            $stream['stream'],
                            [
                                $captain->code,
                                $captain->user->name,
                                $captain->employmentType->name ?? "N/A",
                                $captain->regions->pluck('quadrant.name')->unique()->join(', '),
                                $captain->vehicle->vehicleType->name ?? 'N/A',
                                $captain->status,
                                $worked_days,
                                $per_day_salary,
                                $total_salary,
                                $captain->attended_orders,
                                number_format($captain->total_additional_km_earning??0,2),
                                number_format($captain->avg_commission, 2),
                                number_format($balance ??0,2),
                                $total_earnings
                            ]
                        );
                    }else{
                        fputcsv(
                            $stream['stream'],
                            [
                                $captain->code,
                                $captain->user->name,
                                $captain->employmentType->name ?? "N/A",
                                $captain->regions->pluck('quadrant.name')->unique()->join(', '),
                                $captain->vehicle->vehicleType->name ?? 'N/A',
                                $captain->status,
                                $captain->attended_orders,
                                number_format($captain->total_additional_km_earning??0,2),
                                number_format($captain->avg_commission, 2),
                                number_format($balance ??0,2)
                            ]
                        );
                    }
                        
                    
                }

                $path = $this->putData($stream['tempLocalFilePath'],$fileName);
                fclose($stream['stream']);

                $nextPageUrl = $captains->nextPageUrl();
                $nextPage = null;
                if(!is_null($nextPageUrl)) {
                    $nextPage = explode('=', $nextPageUrl, 2)[1];
                }

                if(is_null($nextPage)) {
                    // we are done processing
                    $this->export->update([
                        'status' => 'processed',
                        'status_message' => 'Completed',
                        'is_ready_for_download'=>1,
                        'notify'=>1,
                        'page_done'=>$this->page
                    ]);
                    Mail::to($this->export->email_id)->send(new ExportReportMail($this->export));
                    return;
                }

                // refresh to get current state of export before using it for next job
                $this->export->refresh();

                dispatch(new static($this->export, $fileName, $nextPage, $this->request));
                
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

    public function getReport() {

        $from_date = isset($this->request['from_date']) ? Carbon::parse($this->request['from_date'])->format('Y-m-d 00:00:00') : '';
        $to_date = isset($this->request['to_date']) ? Carbon::parse($this->request['to_date'])->format('Y-m-d 23:59:59') : '';
        $captain = isset($this->request['captain'])?$this->request['captain']:'';
        $captain_id = isset($this->request['captain_id'])?$this->request['captain_id']:'';
        $region = isset($this->request['region'])?$this->request['region']:'';
        $vehicle_type = isset($this->request['vehicle_type'])?$this->request['vehicle_type']:'';
        $status = isset($this->request['status'])?$this->request['status']:'';
        $job_type = isset($this->request['job_type'])?$this->request['job_type']:'';
        $payment_status = isset($this->request['payment_status'])?$this->request['payment_status']:'';
           
        $checked_values = isset($this->request['checked_values'])?$this->request['checked_values']:[];

         $captains = Captain::query()
             ->with('user', 'employmentType','lastCommission','filterCommissions', 'region', 'region.quadrant')
             ->leftJoin(
                DB::raw('(SELECT MAX(id) AS max_id, captain_id FROM captain_commissions GROUP BY captain_id) as max_commissions'),
                'captains.id',
                '=',
                'max_commissions.captain_id'
            )
            ->leftJoin('captain_commissions', 'max_commissions.max_id', '=', 'captain_commissions.id')
            ->withCount(['orders as attended_orders' => function ($query) use($from_date, $to_date) {
                $query->has('captainCommission')->when($from_date, function($query, $from_date) {
                    $query->where('orders.delivery_date', '>=', now()->parse($from_date)->format('Y-m-d'). ' 00:00:00');
                })
                ->when($to_date, function($query, $to_date) {
                    $query->where('orders.delivery_date', '<=', now()->parse($to_date)->format('Y-m-d'). ' 23:59:59');
                })
                ->whereIn('orders.status_id', [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED]);
            }])
            ->withAvg(['commissions as avg_commission' => function ($query) use ($from_date, $to_date) {
                $query->when($from_date && $to_date, function ($query) use ($from_date, $to_date) {
                    return $query->whereBetween('captain_commissions.created_at', [
                        now()->parse($from_date)->format('Y-m-d') . ' 00:00:00',
                        now()->parse($to_date)->format('Y-m-d') . ' 23:59:59'
                    ]);
                });
            }], 'commission')
            ->withSum(['commissions as total_commission' => function ($query) use ($from_date, $to_date) {
                $query->join('orders','orders.id','captain_commissions.order_id')
                ->when($from_date && $to_date, function ($query) use ($from_date, $to_date) {
                    return $query->whereBetween('orders.delivery_date', [
                        now()->parse($from_date)->format('Y-m-d') . ' 00:00:00',
                        now()->parse($to_date)->format('Y-m-d') . ' 23:59:59'
                    ]);
                });
               
            }], 'commission')
            ->withSum(['commissions as total_additional_km_earning' => function ($query) use ($from_date, $to_date) {
                $query->when($from_date && $to_date, function ($query) use ($from_date, $to_date) {
                    return $query->whereBetween('captain_commissions.created_at', [
                        now()->parse($from_date)->format('Y-m-d') . ' 00:00:00',
                        now()->parse($to_date)->format('Y-m-d') . ' 23:59:59'
                    ]);
                });
            }], 'additional_km_earning')
            ->withSum(['commissions as total_filter_commission' => function ($query) use ($from_date, $to_date) {
                $query->join('orders','orders.id','captain_commissions.order_id')
                ->when($from_date && $to_date, function ($query) use ($from_date, $to_date) {
                    return $query->whereBetween('orders.delivery_date', [
                        now()->parse($from_date)->format('Y-m-d') . ' 00:00:00',
                        now()->parse($to_date)->format('Y-m-d') . ' 23:59:59'
                    ]);
                });
            }], 'commission');
          
           // ->allCommissionedCaptain();
        
            if ($from_date || $to_date) {
                $captains->when($from_date, function ($query, $from_date) {
                    return $query->whereHas('orders', function ($query) use ($from_date) {
                        $query->where('delivery_date', '>=', now()->parse($from_date)->format('Y-m-d'). ' 00:00:00');
                    });  
                })
                ->when($to_date, function ($query, $to_date) {
                    return $query->whereHas('orders', function ($query) use ($to_date) {
                        $query->where('delivery_date', '<=', now()->parse($to_date)->format('Y-m-d'). ' 23:59:59');
                    });  
                })->when($from_date, function ($query, $from_date) {
                    return $query->whereHas('commissions', function ($query) use ($from_date) {
                        $query->where('created_at', '>=', now()->parse($from_date)->format('Y-m-d'). ' 00:00:00');
                    });  
                })->when($to_date, function ($query, $to_date) {
                    return $query->whereHas('commissions', function ($query) use ($to_date) {
                        $query->where('created_at', '<=', now()->parse($to_date)->format('Y-m-d'). ' 23:59:59');
                    });  
                });
                
            }
            
     return  $captains =  $captains->when($captain, function ($query, $captain) {
                return $query->where('captains.id', $captain);
            })
            ->when($captain_id, function ($query, $captain_id) {
                return $query->where('captains.id', $captain_id);
            })
            ->when($region, function ($query, $region) {
                $query->whereHas('regions.quadrant', function($query) use ($region) {
                    $query->where('quadrants.id', $region);
                });
            })
            ->when($vehicle_type, function ($query, $vehicle_type) {
                return $query->whereHas('vehicle.vehicleType', function ($query) use ($vehicle_type) {
                    $query->where('id', $vehicle_type);
                });
            })
            ->when($status, function ($query, $status) {
                return $query->where('captains.status', $status);
            })
            ->when(!$status, function ($query, $status) {
                return $query->where('captains.status', 'active');
            })
            ->when($job_type, function ($query, $job_type) {
                return $query->where('captain_employment_type_id', $job_type);
            })
            ->when($payment_status, function($query, $payment_status) {
                if($payment_status == 'Payable') {
                    $query->where('balance', '>', 0);
                }

                if($payment_status == 'Tally') {
                    $query->where('balance', '=', 0);
                }
            })
            ->when($checked_values, function ($query, $checked_values) {
                return $query->whereIn('captains.id', $checked_values);
            })
            ->paginate(50, ['*'], 'page', $this->page);
    }

    function getColumns(){

        if(isset($this->request['salary_from_date']))
            return  $columns = [
                'Caption ID',
                'Captain Name',
                'Employment Type',
                'Work Region',
                'Vehicle Type',
                'Status',
                'Days Count',
                'Daily Salary',
                'Total Salary',
                'Attended Orders',
                'Extra Km Eranings',
                'Avrg Com/ Order',
                'Total Commission',
                'Total Earnings'
            ];
        else
            return  $columns = [
                    'Caption ID',
                    'Captain Name',
                    'Employment Type',
                    'Work Region',
                    'Vehicle Type',
                    'Status',
                    'Attended Orders',
                    'Extra Km Eranings',
                    'Avrg Com/ Order',
                    'Total Commission'
                ];
    }
}
