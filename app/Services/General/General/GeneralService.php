<?php
namespace App\Services\General\General;

use App\Interfaces\General\GeneralInterface;
use App\Partner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;


class GeneralService
{
    public function __construct(private readonly GeneralInterface $generalInterface){}

    public function getPartnerList($search,$perPage)
    {
        return $this->generalInterface->getPartnerList($search,$perPage);
    }

    public function getThirdPartyCompanyList(array $filters,int $perPage)
    {
        return $this->generalInterface->getThirdPartyCompanyList($filters,$perPage);
    }

    public function createPartner(array $data)
    {
        return $this->generalInterface->createPartner($data);
    }

    public function getPartnerById(int $id): Partner
    {
        return $this->generalInterface->getPartnerById($id);
    }

    public function updatePartner(int $id, array $data, ?UploadedFile $file = null)
    {
        $partner = $this->generalInterface->getPartnerById($id);

        if ($file instanceof UploadedFile) {
            // Using Storage facade for consistency, target same path as create
            if (!empty($partner->documents) && Storage::disk('public')->exists($partner->documents)) {
                 // Storage cleanup (optional based on trait usage)
            }
        }

        $data['updated_by'] = Auth::id();

        $updated = $this->generalInterface->updatePartner($id, $data);

        // Build expiry payload
        $expire = [
            'name' => ($data['first_name'] ?? $updated->first_name) . ' ' . ($data['last_name'] ?? $updated->last_name),
            'date' => $data['agreement_expiry_date'] ?? $updated->agreement_expiry_date,
            'type' => 'Partner',
            'detail' => 'agreement',
            'reference_path' => "/partners/{$id}/edit",
            'reference_id' => $id,
        ];

        $this->generalInterface->updateExpiryReminder($id, $expire);

        return $updated;
    }

    public function createThirdPartyCompany(array $data)
    {
        return $this->generalInterface->createThirdPartyCompany($data);
    }

    public function getThirdPartyCompanyDetails(int $id)
    {
        return $this->generalInterface->getThirdPartyCompanyById($id);
    }

    public function updateThirdPartyCompany(int $id, array $data)
    {
        return $this->generalInterface->updateThirdPartyCompany($id, $data);
    }

    public function getCaptainWalletSummary(int $id)
    {
        return $this->generalInterface->getCaptainWalletSummary($id);
    }

    public function getCaptainSafeCustodyAmountSummary(int $id)
    {
        return $this->generalInterface->getCaptainSafeCustodyAmountSummary($id);
    }

    public function getCaptainCreditableAmountSummary(int $id)
    {
        return $this->generalInterface->getTransactionAmountSummary($id);
    }

    public function getCaptainReceivableAmountSummary(int $id)
    {
        return $this->generalInterface->getTransactionAmountSummary($id);
    }

    public function getAccountSafeCustodyModal(int $id)
    {
        $account =  $this->generalInterface->getCaptainSafeCustodyAmountSummary($id);

        $data = [
            'fixed_custody_amount' => (float) ($account->fixed_custody_amount ?? 0),
            'amount_payable' => (float) ($account->amount_payable ?? 0)
        ];

        return $data;
    }

    public function getAccountReceivableModal(int $id)
    {
        $account =  $this->generalInterface->getAccountReceivable($id);

        $data = [
            'current_amount' => (float) ($account->creditable_amount ?? 0),
        ];
        
        return $data;
    }
    public function createCaptainSafeCustodyAmount(array $data)
    {
        return $this->generalInterface->createCaptainSafeCustodyAmount($data);
    }

    public function createCaptainReceivableAmount(array $data)
    {
        return $this->generalInterface->createCaptainReceivableAmount($data);
    }

    public function getCompanies(array $filters = [],$perPage)
    {
        return $this->generalInterface->getCompanies($filters,$perPage);
    }

    public function getCompanyDetails(int $id)
    {
        return $this->generalInterface->getCompanyDetails($id);
    }

    public function updateCompany(int $id, array $data)
    {
        $companyData = [
            'name' => $data['name'],
            'email' => $data['email'],
            'mobile_no' => $data['mobile_no'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'app_version_ios' => $data['app_version_ios'] ?? null,
            'website' => $data['website'] ?? null,
            'vat_id' => $data['vat_id'] ?? null,
            'about' => $data['about'] ?? null,
            'min_supported_version' => $data['min_supported_version'] ?? null,
            'min_supported_version_ios' => $data['min_supported_version_ios'] ?? null,
        ];

        $terms = collect($data['add_more'] ?? [])->map(fn ($item) => 
                [
                    'company_id' => $id,
                    'term' => $item['term'] ?? null,
                    'condition' => $item['condition'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->toArray();

        $policies = collect($data['add_policy'] ?? [])->map(fn ($item) => 
            [
                'company_id' => $id,
                'policy' => $item['policy'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray();

        $media = collect($data['add_media'] ?? [])->map(fn ($item) => 
            [
                'company_id' => $id,
                'media_term' => $item['media_term'] ?? null,
                'link' => $item['link'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray();

        return $this->generalInterface->updateCompany($id, ['company' => $companyData,'terms' => $terms,'policies' => $policies,'media' => $media]);

    }
   
}