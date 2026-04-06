<?php

namespace App\Repositories\General\Reports;

use App\Interfaces\General\Reports\CaptainKPIReportInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use App\Captain;
use App\CaptainWorkingLog;  

class CaptainKPIReportInterfaceRepository implements CaptainKPIReportInterface
{
    public function getCaptains(array $filters,int $perPage): LengthAwarePaginator
    {
        return Captain::query()
            ->select('id','iqama_number')
            ->with(['regions.quadrant','company'])
            ->withName()
            ->when($filters['areas_id'] ?? null, function ($query, $areas) {
                $query->whereHas('regions', function ($q) use ($areas) {
                    $q->whereIn('region_id', $areas);
                });
            })
            ->when($filters['job_type'] ?? null, fn($q,$type)=>$q->where('captain_employment_type_id',$type))
            ->when($filters['companies'] ?? null, function ($query, $companies) {
                $query->whereHas('company', fn($q)=>$q->whereIn('third_party_logistic_companies.id',$companies));
            })
            ->when($filters['regions'] ?? null, function ($query, $regions) {
                $query->whereHas('regions.quadrant', fn($q)=>$q->whereIn('quadrant_id',$regions));
            })
            ->when($filters['captain_id'] ?? null, fn($q,$ids)=>$q->whereIn('id',$ids))
            ->when($filters['status'] ?? null, fn($q,$status)=>$q->where('status',$status))
            ->paginate($perPage);
    }

    public function getWorkingDays(array $filters, array $captainIds)
    {
        return DB::table('captain_working_logs')
            ->select('captain_id')
            ->addSelect(DB::raw("DATE(date) as date"))
            ->addSelect(DB::raw("SUM(seconds_worked) as working_seconds"))
            ->addSelect(DB::raw("SUM(orders_delivered) as completed_orders"))
            ->whereDate('date','>=',$filters['from'])
            ->whereDate('date','<=',$filters['to'])
            ->whereIn('captain_id',$captainIds)
            ->groupByRaw("DATE(date), captain_id")
            ->get();
    }

    public function getCaptainPerformanceReport(array $filters, int $perPage): LengthAwarePaginator
    {
        return CaptainWorkingLog::query()
            ->with([
                'captain:id,code,iqama_number,captain_employment_type_id,user_id',
                'captain.user:id,name',
                'captain.employmentType:id,name',
                'captain.company:third_party_logistic_companies.id,third_party_logistic_companies.name',
                'captain.regions.quadrant:id,name'
            ])
            ->select('captain_id')
            ->addSelect([
                DB::raw('SUM(seconds_worked) as total_seconds_worked'),
                DB::raw('SUM(orders_received) as total_orders_received'),
                DB::raw('SUM(orders_accepted) as total_orders_accepted'),
                DB::raw('SUM(orders_delivered) as total_orders_delivered'),
                DB::raw('SUM(orders_returned) as total_orders_returned'),
                DB::raw('SUM(orders_cancelled) as total_orders_cancelled'),
                DB::raw('SUM(orders_rejected) as total_orders_rejected'),
                DB::raw('SUM(orders_try_to_accept) as orders_try_to_accept'),
                DB::raw('SUM(orders_expired) as no_of_no_response_requests'),
                DB::raw('COUNT(DISTINCT date) as working_days'),
                DB::raw('SUM(CASE WHEN orders_delivered > 0 THEN 1 ELSE 0 END) as productive_days')
            ])

            ->when($filters['captain'] ?? null,
                fn($q,$captain)=>$q->whereIn('captain_id',$captain)
            )

            ->when($filters['status'] ?? null,function($q,$status){
                $q->whereHas('captain',fn($x)=>$x->where('status',$status));
            })

            ->when($filters['regions'] ?? null,function($q,$regions){
                $q->whereHas('captain.regions.quadrant',fn($x)=>$x->whereIn('quadrants.id',$regions));
            })

            ->when($filters['areas_id'] ?? null,function($q,$areas){
                $q->whereHas('captain.regions',fn($x)=>$x->whereIn('regions.id',$areas));
            })

            ->when($filters['employment_type'] ?? null,function($q,$type){
                $q->whereHas('captain',fn($x)=>$x->where('captain_employment_type_id',$type));
            })

            ->when($filters['companies'] ?? null,function($q,$companies){
                $q->whereHas('captain.company',fn($x)=>$x->whereIn('third_party_logistic_companies.id',$companies));
            })

            ->whereHas('captain', function ($q) {
                $q->whereIn('status', [
                    Captain::STATUS_ACTIVE,
                    Captain::STATUS_BANNED,
                    Captain::STATUS_INACTIVE
                ]);
            })
            ->whereBetween('date',[$filters['from'],$filters['to']])
            ->when($filters['q'] ?? null, function ($query, $q) {
                return $query->whereHas('captain.user', function ($query) use ($q) {
                    $query->where('name', 'like', '%' . $q . '%')
                        ->orWhere('email', 'like', '%' . $q . '%');
                })->orWhereHas('captain', function ($query) use ($q) {
                    $query->where('code', 'like', '%' . $q . '%')
                        ->orWhere('iqama_number', 'like', '%' . $q . '%');
                });
            })
            ->groupBy('captain_id')
            ->when($filters['sort_by'] ?? null, function ($query, $by) use ($filters) {
                $order = $filters['sort_order'] ?? 'asc';
                $order = strtolower($order) == 'asc' ? 'asc' : 'desc';

                if ($by == 'acceptance_rate') {
                     $query->orderByRaw('SUM(orders_accepted) / SUM(orders_received) ' . $order);
                }

                if ($by == 'success_rate') {
                    $query->orderByRaw('SUM(orders_delivered) / SUM(orders_received) ' . $order);
                }
            })
            ->orderBy('captain_id')
            ->paginate($perPage);
    }
}