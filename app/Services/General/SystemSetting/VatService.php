<?php
namespace App\Services\General\SystemSetting;

use App\Interfaces\General\SystemSettingInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class VatService
{
    public function __construct(private readonly SystemSettingInterface $systemSettingInterface)
    {
    }

    public function getVatList(array $filters,$perPage)
    {
        return $this->systemSettingInterface->getVatList($filters,$perPage);
    }

    public function createVat(array $data)
    {
        $this->systemSettingInterface->deactivateAllVat();
        $data['created_by'] = Auth::id();
        $data['status'] = 'active';
        $data['is_price_include'] = 1;
        return $this->systemSettingInterface->createVat($data);
    }

    public function getVatDetails(int $id)
    {
        return $this->systemSettingInterface->getVatDetails($id);
    }

    public function updateVat(int $id, array $data)
    {
        return $this->systemSettingInterface->updateVat($id, $data);
    }

    public function removeVat(int $id)
    {
        return $this->systemSettingInterface->deleteVat($id);
    }

    public function updateVatStatus(int $id)
    {
        return DB::transaction(function () use ($id) {

            $this->systemSettingInterface->deactivateAllVat();

            return $this->systemSettingInterface->updateVatStatus($id,Auth::id() );
        });

    }

}