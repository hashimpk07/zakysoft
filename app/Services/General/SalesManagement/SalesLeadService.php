<?php

namespace App\Services\General\SalesManagement;

use App\Interfaces\General\SalesLeadInterface;
use App\LeadStatus;
use App\SalesLead;
use App\Traits\HasFileUpload;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SalesLeadService
{
    use HasFileUpload;

    public function __construct(private readonly SalesLeadInterface $repository)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getStatusSummary(): array
    {
        $counts = $this->repository->getStatusCounts();

        return collect(LeadStatus::STATUS_IDS)
            ->map(fn($statusId, $key) => [
                'name' => $key,
                'label' => Str::of($key)->replace('_', ' ')->title(),
                'count' => $counts->get($statusId, 0),
                'id' => $statusId
            ])
            ->values()
            ->toArray();
    }

    public function getLeads(Request $request)
    {
        return $this->repository->getLeads($request);
    }

    public function addNote(SalesLead $lead, array $payload)
    {
        return $this->repository->createNote($lead, $payload);
    }

    public function updateLeadStatus(SalesLead $lead, array $payload)
    {
        return $this->repository->updateLead($lead, $payload);
    }

    public function showServiceLead(SalesLead $lead)
    {
        return $this->repository->load($lead, function ($lead) {
            $lead->loadMissing([
                'assigners',
                'locations',
                'logs.createdBy:id,name',
                'notes.createdBy:id,name',
                'documents',
                'brands',
                'shops'
            ]);
        });
    }

    public function saveLead(array $data, SalesLead $lead = null): SalesLead 
    {
        return DB::transaction(function () use ($data, $lead) {
            $isUpdate = isset($lead);
            
            // 1. Map and Process Files
            $processedData = $this->prepareData($data, $lead);

            // 2. Persist Lead
            if ($isUpdate) {
                $processedData['updated_by'] = auth()->id();
                $lead = $this->repository->update($lead, $processedData);
            } else {
                $processedData['created_by'] = auth()->id();
                $processedData['status_id'] = LeadStatus::NEW_CLIENT;
                $lead = $this->repository->create($processedData);
            }

            // 3. Relationships & Bank
            $this->repository->syncRelations($lead, $data);
            $this->repository->handleBank($lead, [
                "bank_id"        => $data['bank'] ?? null,
                "name"           => $data['account_name'] ?? null,
                "account_number" => $data['account_number'] ?? null,
                "iban_number"    => $data['iban_number'] ?? null,
            ]);

            // 4. Documents
            $this->processDocuments($lead, $data);

            return $lead;
        });
    }

    private function prepareData(array $data, ?SalesLead $lead): array {
        // Map inconsistent keys (industry -> industry_id)
        $data['industry_id'] = $data['industry'];
        $data['region_id'] = $data['region'];
        $data['point_of_contact_position_id'] = $data['point_of_contact_position'];
        $data['type_of_client_id'] = $data['integration_method'];
        $data['platform_id'] = $data['platform'];
        $data['feedback_id'] = $data['feedback'];
        $data['collaboration_priority_id'] = $data['priority'];

        // File handling
        $fileMap = [
            'company_logo'    => 'logo', 
            'cr_registration' => 'cr_registration', 
            'vat'             => 'vat', 
            'owner_id_card'   => 'owner_id_card'
        ];

        foreach ($fileMap as $input => $column) {
            if (isset($data[$input]) && $data[$input] instanceof UploadedFile) {
                $oldFile = $lead ? $lead->$column : null;
                $data[$column] = $this->uploadFile($data[$input], 'public/sales-leads', $oldFile);
            }
        }
        return $data;
    }

    private function processDocuments($lead, $data): void {
        $newDocs = [];
        if (isset($data['documents'])) {
            foreach ($data['documents'] as $key => $file) {
                $newDocs[] = [
                    'name' => $data['documents_name'][$key] ?? 'Other Document',
                    'path' => $this->uploadFile($file, 'public/sales-leads')
                ];
            }
        }
        $this->repository->handleDocuments($lead, $newDocs, $data['attached_documents'] ?? []);
    }

}