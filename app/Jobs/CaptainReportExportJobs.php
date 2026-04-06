<?php

namespace App\Jobs;

use App\Captain;
use App\Exports\QueueExport;
use Illuminate\Support\Facades\Log;

class CaptainReportExportJobs extends QueueExport
{
    protected int $chunk = 1000;
    protected int $totalData = 0;
    protected string $file_name = 'captain_report';

    public $tries = 3;
    public $timeout = 900;
    public $retryAfter = 60;

    /**
     * Prepare data for Excel export.
     */
    public function data(): array
    {
        try {
          
            $captainReports = $this->getReport();

            // Format data for Excel export
            $data = [];
            foreach ($captainReports as $report) {
                $data[] = [
                    $report['code'] ?? '',
                    $report['name'] ?? '',
                    $report['iqama_no'] ?? '',
                    $report['mobile_number'] ?? '',
                    $report['nationality'] ?? '',
                    $report['total_delivery'] ?? '',
                    $report['region'] ?? '',
                    $report['area'] ?? '',
                    $report['priority'] ?? '',
                    $report['status'] ?? '',
                    $report['shift_status'] ?? '',
                    $report['vehicle_type'] ?? '',
                    $report['vehicle'] ?? '',
                    $report['employment_type'] ?? '',
                    $report['app_current_version'] ?? '',

                ];
            }

            return $data;
        } catch (\Throwable $e) {
            Log::channel('commission')->error('CaptainReportExcelJob failed: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Get captain report data with filters.
     */
   private function getReport(): array
    {
        try {
           
            $request = $this->export->filters;
            $search       = isset($request['search']) ? $request['search'] : false;
            $mobileNumber = isset($request['mobile_no']) ? $request['mobile_no'] : '';
            $iqama        = isset($request['iqama']) ? $request['iqama'] : '';
            $code         = isset($request['code']) ? $request['code'] : '';
            $shift_status         = isset($request['shift_status']) ? $request['shift_status'] : '';
            $third_party_company_id         = isset($request['third_party_company_id']) ? $request['third_party_company_id'] : '';
            $nationality         = isset($request['nationality']) ? $request['nationality'] : '';
            $region_id           = isset($request['region_id']) ? $request['region_id'] : '';
            $quadrant_id         = isset($request['quadrant_id']) ? $request['quadrant_id'] : '';
            $vehicle_type        = isset($request['vehicle_type']) ? $request['vehicle_type'] : '';
            $captain             = isset($request['captain']) ? $request['captain'] : '';
            $status              = isset($request['status']) ? $request['status'] : '';
            $job_type              = isset($request['job_type']) ? $request['job_type'] : '';

            // Build query
            $captains = Captain::query()
                ->with([
                'user',
                'regions.quadrant',
                'nationality',
                'currentShift',
                'employmentType',
                'vehicle.vehicleType',
                'nationality',
                'autoAssignPriority',
                'captainThirdParty'
            ])
            ->withCount(['ordersDelivered'])
            ->when($search, fn($q, $search) =>
                $q->whereLike(['user.name', 'user.email', 'phone_number', 'iqama_number', 'licence_number'], $search)
            )
            ->when($mobileNumber, fn($q, $mobile) =>
                $q->where('phone_number', 'like', "%{$mobile}%")
            )
            ->when($code, fn($q, $code) =>
                $q->whereLike(['code'], $code)
            )
            ->when($shift_status, function ($q, $shiftStatus) {
                if ($shiftStatus === 'ONLINE') {
                    $q->has('currentShift');
                } elseif ($shiftStatus === 'OFFLINE') {
                    $q->doesntHave('currentShift');
                }
            })
            ->when($third_party_company_id, function ($q, $id) {
                $q->whereHas('captainThirdParty', fn($q2) =>
                    $q2->where('third_party_logistic_company_id', $id)
                );
            })
            ->when($nationality, fn($q, $nationality) =>
                $q->where('nationality_id', $nationality)
            )
            ->when($region_id, fn($q, $regionId) =>
                $q->where('captains.region_id', $regionId)
            )
            ->when($quadrant_id , function ($q, $quadrantId) {
                $q->whereHas('regions.quadrant', fn($q2) => $q2->where('id', $quadrantId));
            })
            ->when($vehicle_type, function ($q, $vehicleType) {
                $q->whereHas('vehicle', fn($q2) => $q2->where('type', $vehicleType));
            })
             ->when($captain , fn($q, $captainId) =>
                $q->where('captains.id', $captainId)
            )
            ->when($status, fn($q, $status) =>
                $q->where('captains.status', $status)
            )
            ->when($job_type, fn($q, $jobType) =>
                $q->where('captain_employment_type_id', $jobType)
            )
            // ->when($request['sort_by'], function ($q, $sortBy) use ($request) {
            //     $order = $request->get('sort_order', 'ASC');
            //     switch ($sortBy) {
            //         case 'version':
            //             $q->orderByRaw("CAST(current_using_app_version AS DECIMAL(10,2)) $order");
            //             break;

            //         case 'job_type':
            //             $q->orderBy('captain_employment_type_id', $order);
            //             break;

            //         case 'work_status':
            //             $q->orderByRaw("
            //                 CASE captains.status
            //                     WHEN 'Active' THEN 1
            //                     WHEN 'Leave' THEN 2
            //                     WHEN 'Inactive' THEN 3
            //                     WHEN 'Banned' THEN 4
            //                 END $order
            //             ");
            //             break;

            //         case 'online_status':
            //             $q->orderByRaw("
            //                 CASE
            //                     WHEN EXISTS (
            //                         SELECT 1 FROM shift_statuses 
            //                         WHERE shift_statuses.captain_id = captains.id 
            //                         AND shift_end IS NULL
            //                     ) THEN 1
            //                     ELSE 2
            //                 END $order
            //             ");
            //             break;

            //         case 'vehicle_type':
            //             $q->orderByRaw("(SELECT vehicle_types.name 
            //                 FROM vehicles 
            //                 JOIN vehicle_types ON vehicle_types.id = vehicles.vehicle_type_id
            //                 WHERE vehicles.id = captains.vehicle_id 
            //                 LIMIT 1) $order");
            //             break;

            //         case 'nationality':
            //             $q->orderByRaw("(SELECT name 
            //                 FROM nationalities 
            //                 WHERE nationalities.id = captains.nationality_id 
            //                 LIMIT 1) $order");
            //             break;
            //     }
            // })
            ->where('captains.status', '!=', 'Request')
            ->orderBy('id', 'desc')
            ->limit($this->chunk)
            ->offset(($this->chunk * ($this->export->page_done ?? 0)))
            ->get()
            ->map(function ($captain) {
                return [
                    'code' => $captain->code,
                    'name' => $captain->user->name ?? '',
                    'iqama_no' => $captain->iqama_number ?? '',
                    'mobile_number' => $captain->phone_number ?? '',
                    'nationality' => $captain->nationality->name  ?? '',
                    'total_delivery' => $captain->orders_delivered_count ?? 0,
                    'region' => $captain->regions->pluck('quadrant.name')->join(', '),
                    'area' => $captain->regions->pluck('name')->join(', '),
                    'priority' => $captain->autoAssignPriority->name ?? '',
                    'status' => $captain->status === 'Active' ? 'Active' : 'Inactive',
                    'shift_status' => $captain->currentShift ? 'Online' : 'Offline',
                    'vehicle_type' => $captain->vehicle->vehicleType->name ?? '',
                    'vehicle' => $captain->vehicle->number ?? '',
                    'employment_type' => $captain->employmentType->name ?? '',
                    'app_current_version' => $captain->current_using_app_version ?? 'Not Updated',
                ];
            })
            ->toArray(); 

        // Keep total count
        $this->totalData = count($captains);

        return $captains; 
    } catch (\Throwable $e) {
        Log::channel('commission')->error('CaptainReportExcelJobs::getReport failed: ' . $e->getMessage(), [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        return []; // return empty array on failure
    }
}


    /**
     * Excel file headers.
     */
    public function headers(): array
    {
        return [
            'Captain ID',
            'Name',
            'Iqama Number',
            'Mobile Number',
            'Nationality',
            'Total Orders Delivered',
            'Region',
            'Area',
            'Priority',
            'Work status',
            'Shift Status',
            'Vehicle Type',
            'Vehicle No',
            'Employment Type',
            'App Current Version',
        ];
    }

    /**
     * Total count of data exported.
     */
    public function count(): int
    {
        return $this->totalData;
          
    }
}
