<?php
namespace App\Jobs;

use App\Captain;
use App\Exports\QueueExport;
use App\GeneralExport;

class Captain3plExportJob extends QueueExport
{
    protected int $chunk = 1000;
    protected int $totalData = 0;

    protected string $file_name = '3pl_captain_list';

    public function __construct(GeneralExport $export)
    {
        parent::__construct($export);
    }

    // Count distinct users that have at least one daily row in range
    public function count(): int
    {
        return $this->baseQuery()->count();
    }

    public function headers(): array
    {
        return [
            'EMP ID',
            'Captain Name',
            'Mobile No',
            'Total Delivery',
            'Region',
            'Area',
            'Priority',
            'Work Status',
            'Shift Status',
            'Vehicle No',
            'App Current Version',
        ];
    }

    public function data(): array
    {
        $offset = ($this->export->page_done ?? 0) * $this->chunk;

        return $this->baseQuery()->limit($this->chunk)->offset($offset)->get()->map(function ($captain) {
            return [
                $captain->code,
                $captain->user?->name,
                $captain->phone_number,
                $captain->orders_delivered_count,
                $captain->regions->pluck('quadrant.name')->join(', '),
                $captain->regions->pluck('name')->join(', '),
                $captain->autoAssignPriority?->name,
                $captain->status == 'Active' ? 'Active' : 'Inactive',
                $captain->currentShift ? 'Online' : 'Offline',
                $captain->vehicle?->number,
                $captain->current_using_app_version ?? "Not Updated",
            ];
        })->toArray();
    }

    private function baseQuery()
    {
        $filters = $this->export->filters;

        $company_id_3pl = $filters['company_id_3pl'] ?? null;

        $captains = Captain::query()
            ->belongsTo3pl($company_id_3pl)
            ->with('user', 'zone', 'regions.quadrant', 'currentShift', 'autoAssignPriority')
            ->withCount(['ordersDelivered']);

        if (! empty($filters['name'])) {
            $name = trim($filters['name']);
            $captains->where('name', 'like', "%{$name}%");
        }

         if (! empty($filters['mobile_no'])) {
            $phone = trim($filters['mobile_no']);
            $captains->where('phone_number', 'like', "%{$phone}%");
        }

        // Region filter
        if (! empty($filters['region_id'])) {
            $captains->where('region_id', $filters['region_id']);
        }

        // Quadrant filter
        if (! empty($filters['quadrant_id'])) {
            $captains->whereHas('region.quadrant', function ($query) use ($filters) {
                $query->where('id', $filters['quadrant_id']);
            });
        }

        // Vehicle type (JSON column)
        if (! empty($filters['vehicle_type_id'])) {
            $captains->whereJsonContains('type_of_vehicle', $filters['vehicle_type_id']);
        }

        // Captain
        if (! empty($filters['captain_id'])) {
            $captains->where('id', $filters['captain_id']);
        }

        // Status
        if (isset($filters['work_status'])) {
            $captains->where('status', $filters['work_status']);
        }

        // Job type
        if (! empty($filters['job_type'])) {
            $captains->where('job_type', $filters['job_type']);
        }

        // nationality
        if (! empty($filters['nationality_id'])) {
            $captains->where('nationality_id', $filters['nationality_id']);
        }

        return $captains;
    }

}
