<?php

namespace App\Jobs;

use App\Captain;
use App\Exports\QueueExport;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class ThirdPartyCaptainWorkingDaysExportJob extends QueueExport
{

    protected int $chunk = 2000;

    public $timeout = 100000;

    protected string $file_name = 'captain_working_days';


    /**
     * Get the total count of records to be exported.
     */
    public function count(): int
    {
        $request = $this->export->filters;
        $companies = $request['companies'] ?? $request['company_id_3pl'] ?? false;

        return Captain::query()
            ->when($request['areas_id'] ?? [], function ($query, $areas) {
                $query->whereHas('regions', fn($q) => $q->whereIn('region_id', $areas));
            })
            ->when($request['captain_id'] ?? null, function ($query, $captains) {
                $query->where('id', $captains);
            })
            ->when($request['regions_id'] ?? [], function ($query, $regions) {
                $query->whereHas('regions.quadrant', fn($q) => $q->whereIn('quadrant_id', $regions));
            })
            ->when($request['job_type'] ?? [], function ($query, $jobType) {
                $query->where('captain_employment_type_id', $jobType);
            })
            ->when($companies, function ($query, $companies) {
                $query->whereHas(
                    'company',
                    fn($q) =>
                    $q->where('third_party_logistic_companies.id', $companies)
                );
            })
            ->when($request['captain_id'] ?? [], function ($query, $captains) {
                $query->whereIn('id', $captains);
            })
            ->when($request['status'] ?? [], function ($query, $captain_status) {
                $query->where('status', $captain_status);
            })
            ->count();
    }

    /**
     * Get the headers for the export file.
     */
    public function headers(): array
    {
        $request = $this->export->filters;
        $from = $request['from_date'] ?? Carbon::now()->startOfMonth()->toDateString();
        $to = $request['to_date'] ?? Carbon::now()->toDateString();

        $period = CarbonPeriod::create($from, $to);

        $headers = ['Captain', 'Employment Type', 'Iqama No', 'Area', 'Region'];
        foreach ($period as $date) {
            $headers[] = $date->format('m-d-Y') . ' - Working. Hr';
            $headers[] = $date->format('m-d-Y') . ' - O.Count';
        }

        return $headers;
    }

    /**
     * Get the data to be exported.
     */
    public function data(): array
    {
        $request = $this->export->filters;
        $from = $request['from_date'] ?? Carbon::now()->startOfMonth()->toDateString();
        $to = $request['to_date'] ?? Carbon::now()->toDateString();
        $period = CarbonPeriod::create($from, $to);

        $captains = Captain::query()
            ->select('id', 'iqama_number', 'captain_employment_type_id')
            ->with(['regions.quadrant', 'company'])
            ->when($request['areas_id'] ?? [], fn($q, $areas) => $q->whereHas('regions', fn($q) => $q->whereIn('region_id', $areas)))
            ->when($request['job_type'] ?? [], fn($q, $job_type) => $q->where('captain_employment_type_id', $job_type))
            ->when($request['companies'] ?? null, fn($q, $third_party_logistic_company) => $q->whereHas('company', fn($q) => $q->where('third_party_logistic_companies.id', $third_party_logistic_company)))
            ->when($request['regions'] ?? [], fn($q, $regions) => $q->whereHas('regions.quadrant', fn($q) => $q->whereIn('quadrant_id', $regions)))
            ->when($request['captain_id'] ?? [], fn($q, $captains) => $q->whereIn('id', $captains))
            ->when($request['status'] ?? [], fn($q, $captain_status) => $q->where('status', $captain_status))
            ->withName()
            ->limit($this->chunk)
            ->offset($this->chunk * $this->export->page_done ?? 0)
            ->get();


        $workingDays = DB::table('captain_working_logs')
            ->select('captain_working_logs.captain_id')
            ->addSelect(DB::raw("DATE(captain_working_logs.date) as date"))
            ->addSelect(DB::raw("SUM(seconds_worked) as working_seconds"))
            ->addSelect(DB::raw('SUM(orders_delivered) as completed_orders'))
            ->whereBetween(DB::raw('DATE(captain_working_logs.date)'), [$from, $to])
            ->whereIn('captain_working_logs.captain_id', $captains->pluck('id'))
            ->groupByRaw("DATE(captain_working_logs.date), captain_working_logs.captain_id")
            ->get();

        $data = [];
        foreach ($captains as $captain) {
            $days = $workingDays->where('captain_id', $captain->id);
            $row = [
                $captain->name,
                $captain->company->name ?? $captain->employmentType->name ?? '',
                $captain->iqama_number,
                $captain->regions->pluck('name')->join(', '),
                $captain->regions->pluck('quadrant.name')->join(', '),
            ];

            foreach ($period as $date) {
                $day = $days->firstWhere('date', $date->toDateString());
                $row[] = $day ? gmdate('H:i:s', $day->working_seconds) : '';
                $row[] = $day->completed_orders ?? '';
            }

            $data[] = $row;
        }

        return $data;
    }
}
