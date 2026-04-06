<?php

namespace App\Repositories\General;

use App\Interfaces\General\SalesLeadInterface;
use App\LeadStatus;
use App\SalesLead;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class SalesLeadInterfaceRepository implements SalesLeadInterface
{
    public function __construct(
        private readonly SalesLead $model,
    ) {
    }

    public function getStatusCounts(): Collection
    {
        return $this->model::query()
            ->selectRaw('status_id, COUNT(*) as total')
            ->whereIn('status_id', array_values(LeadStatus::STATUS_IDS))
            ->groupBy('status_id')
            ->pluck('total', 'status_id');
    }

    public function getLeads(Request $request): LengthAwarePaginator
    {
        return $this->model::query()
            ->with(['quadrant', 'integrationMethod', 'feedback', 'priority'])
            ->when($request->integer('status'), fn($q, $v) => $q->where('status_id', $v))
            ->when($request->str('q')->trim()->value(), fn($q, $v) => $q->whereLike(['name', 'name_ar', 'createdBy.name', 'createdBy.email', 'point_of_contact_name', 'point_of_contact_email', 'head_office_location'], $v))
            ->when($request->integer('quadrant'), fn($q, $v) => $q->where('region_id', $v))
            ->when($request->integer('priority'), fn($q, $v) => $q->where('collaboration_priority_id', $v))
            ->when($request->integer('leads_by'), fn($q, $v) => $q->where('created_by', $v))
            ->paginate($request->get('per_page', 10));

    }
    public function createNote(SalesLead $lead, array $data)
    {
        return $lead->notes()->create($data);
    }

    public function updateLead(SalesLead $lead, array $payload): SalesLead
    {
        $lead->update($payload);
        return $lead->fresh();
    }

    public function load(SalesLead $lead, ?Closure $callback = null): SalesLead{
        if ($callback) {
            $callback($lead);
        }

        return $lead;
    }

    public function create(array $data): SalesLead {
        return SalesLead::create($data);
    }

    public function update(SalesLead $lead, array $data): SalesLead {
        $lead->update($data);
        return $lead;
    }

    public function syncRelations(SalesLead $lead, array $data): void {
        $lead->assigners()->sync($data['sales_managers'] ?? []);
        $lead->locations()->sync($data['existing_regions'] ?? []);
    }

    public function handleBank(SalesLead $lead, array $bankData): void {
        if (!empty($bankData['bank_id'])) {
            // UpdateOrCreate prevents deleting/recreating IDs unnecessarily
            $lead->bank()->updateOrCreate([], $bankData);
        } else {
            $lead->bank()->delete();
        }
    }

    public function handleDocuments(SalesLead $lead, array $newDocs, array $keepIds = []): void {
        // Only relevant for updates: delete documents not present in 'keep' list
        $lead->documents()->whereNotIn('id', $keepIds)->delete();
        
        if (!empty($newDocs)) {
            $lead->documents()->createMany($newDocs);
        }
    }

}