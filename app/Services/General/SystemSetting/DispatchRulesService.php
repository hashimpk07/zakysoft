<?php

namespace App\Services\General\SystemSetting;

use App\Interfaces\General\SystemSettingInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\CommissionRule;

class DispatchRulesService
{
    public function __construct(private readonly SystemSettingInterface $systemSettingInterface)
    {
    }

    public function getDispatchRuleList(array $filters, int $perPage)
    {
        return $this->systemSettingInterface->getDispatchRuleList($filters, $perPage);
    }

    public function createDispatchRule(array $data)
    {
        return $this->systemSettingInterface->createDispatchRule($data);
    }

    public function getDispatchRuleDetails(int $id)
    {
        return $this->systemSettingInterface->getDispatchRuleDetails($id);
    }

    public function updateDispatchRule($dispatchRule, $data)
    {
        return $this->systemSettingInterface->updateDispatchRule($dispatchRule, $data);
    }

    public function dispatchRuleStoresList($dispatchRuleId, $filters,$perPage)
    {
        return $this->systemSettingInterface->getDispatchRuleStoresList($dispatchRuleId, $filters,$perPage);
    }

    public function dispatchRuleAssignStoresList($dispatchRuleId, $filters,$perPage)
    {
        return $this->systemSettingInterface->dispatchRuleAssignStoresList($dispatchRuleId, $filters,$perPage);
    }

    public function dispatchRuleAssignStores($data)
    {
        return $this->systemSettingInterface->createDispatchRuleAssignStores($data);
    }

    public function dispatchAssignStoreValidation($data)
    {
        return $this->systemSettingInterface->dispatchAssignStoreValidation($data);
    }
}