<?php

namespace App\Services\General\SalesManagement;

use App\Interfaces\General\TeamManagementInterface;
use App\SalesManager;
use App\Traits\HasFileUpload;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class TeamManagementService
{
    use HasFileUpload;
    public function __construct(protected readonly TeamManagementInterface $interface) {}

    public function getManagerList(Request $request)
    {
        $managers =  $this->interface->getPaginatedManagers(filters: $request->all(), perPage: $request->get('per_page', 10));
        $managers->getCollection()->transform(function ($manager) {
            return [
                'id' => $manager->id,
                'id_formatted' => sprintf('SE %03d', $manager->id),
                'name'         => $manager->user->name ?? 'N/A',
                'email'        => $manager->user->email ?? 'N/A',
                'mobile'       => $manager->mobile_number,
                'designation'  => $manager->designation->name ?? 'N/A',
                'region'       => $manager->region->name ?? 'N/A',
                'status_html'  => $manager->user->status ?? 'inactive',
            ];
        });

        return $managers;
    }

    public function saveManager(array $data, ?SalesManager $manager = null): SalesManager 
    {
        return DB::transaction(function () use ($data, $manager) {
            $isUpdate = isset($manager);

            // 1. Handle User Logic
            $user = $this->interface->updateOrCreateUser(
                $this->mapUserData($data), 
                $isUpdate ? $manager->user : ($this->interface->findUser($data['employee'] ?? 0))
            );

            // 2. Handle Manager Logic
            $managerData = $this->mapManagerData($data, $user->id);
            $manager = $isUpdate 
                ? $this->interface->update($manager, $managerData) 
                : $this->interface->create($managerData);

            // 3. Handle Files & Documents
            $this->processFiles($manager, $data, $isUpdate);

            return $manager;
        });
    }

    private function mapUserData(array $data): array {
        $userData = [
            'name'    => $data['name'],
            'status'  => $data['status'],
            'role_id' => $data['permission'],
        ];

        if (!empty($data['password'])) {
            $userData['password'] = bcrypt($data['password']);
        }

        if (!isset($data['employee'])) { // If new user
            $userData['email'] = $data['email'];
        }

        return $userData;
    }

    private function mapManagerData(array $data, int $userId): array {
        return [
            'user_id'           => $userId,
            'mobile_number'     => $data['mobile_number'],
            'joining_date'      => $data['joining_date'],
            'iqama_number'      => $data['iqama_number'],
            'nationality_id'    => $data['nationality'],
            'working_region_id' => $data['region'],
            'designation_id'    => $data['role'],
        ];
    }

    private function processFiles(SalesManager $manager, array $data, bool $isUpdate): void {
        // Handle Iqama Copy
        if (isset($data['iqama']) && $data['iqama'] instanceof UploadedFile) {
            $existingIqama = $manager->documents()->where('name', 'Iqama Copy')->first();
            $path = $this->uploadFile($data['iqama'], 'public/sales-managers', $existingIqama?->path);
            
            $manager->documents()->updateOrCreate(
                ['name' => 'Iqama Copy'],
                ['path' => $path]
            );
        }

        // Handle Other Documents
        $newDocs = [];
        foreach ($data['documents'] ?? [] as $document) {
            $newDocs[] = [
                'name' => 'Other Document',
                'path' => $this->uploadFile($document, 'public/sales-managers')
            ];
        }
        $this->interface->handleDocuments($manager, $newDocs, $isUpdate);
    }
}
