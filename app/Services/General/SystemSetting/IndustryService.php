<?php
namespace App\Services\General\SystemSetting;

use App\Interfaces\General\SystemSettingInterface;
use Illuminate\Support\Facades\Auth;



class IndustryService
{
    public function __construct(private readonly SystemSettingInterface $systemSettingInterface)
    {
    }

    public function createIndustry(array $data)
    {
        return $this->systemSettingInterface->createIndustry($data);
    }

    public function getIndustries(array $filters,$perPage)
    {
        return $this->systemSettingInterface->getIndustryList($filters,$perPage);
    }

    public function getIndustryDetails(int $id)
    {
        return $this->systemSettingInterface->getIndustryDetails($id);
    }

    public function updateIndustry(int $id, array $data)
    {
        return $this->systemSettingInterface->updateIndustry($id, $data);
    }

}