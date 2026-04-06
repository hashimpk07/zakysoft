<?php

namespace App\Interfaces\General;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;


interface SystemSettingInterface
{
    public function getUserActiveTokens($user, int $perPage = 10);
    public function getAllScopes();
    public function removeToken(int $id): bool;
    public function createToken($user, string $name, array $scopes): string;
    public function createIndustry(array $data);
    public function getIndustryList(array $data,int $perPage);
    public function getIndustryDetails($id);
    public function updateIndustry(int $id,array $data);
    public function getVatList(array $data,int $perPage);
    public function createVat(array $data);
    public function deactivateAllVat();
    public function getVatDetails($id);
    public function updateVat(int $id,array $data);
    public function deleteVat(int $id);
    public function updateVatStatus(int $id, int $updatedBy);
    public function getRoleList();
    public function createRole(array $data);
    public function getRoleWithPermissions(int $roleId);
    public function updatePermissions(int $roleId, array $permissions);
    public function deleteRole(int $roleId): bool;
    public function getCommissionRuleList(array $filters, int $perPage);
    public function createDeliveryBasedCommissionRule(array $data);
    public function createKplBasedCommissionRule(array $data);
    public function updateDeliveryBasedCommissionRule(int $id, array $data);
    public function updateKplBasedCommissionRule(int $id, array $data);
    public function detailsCommissionRule(int $id);
    public function detailsDeliveryBasedCommissionRule(int $id);
    public function bulkCommissionRuleStatusUpdate(array $ids, int $status);
    public function addClientByCommissionRule(int $ruleId, array $clientIds);
    public function getCommissionRulesCaptain();
    public function commissionRulesCaptainDetails(array $data,int $perPage);
    public function addCaptainByCommissionRules(int $ruleId, array $captains);
    public function getDispatchRuleList(array $data,int $perPage);
    public function createDispatchRule(array $data);
    public function getDispatchRuleDetails(int $id);
    public function updateDispatchRule($dispatchRule, array $data);
    public function getDispatchRuleStoresList(int $dispatchRuleId,array $filters,int $perPage);
    public function dispatchRuleAssignStoresList(int $dispatchRuleId,array $filters,int $perPage);
    public function createDispatchRuleAssignStores(array $data);
    public function dispatchAssignStoreValidation(array $data);
    public function getShiftRuleList(array $data,int $perPage);
    public function createShiftRule(array $data);
    public function getShiftRuleDetails(int $id);
    public function getShiftRuleLogsDetails(int $id);
    public function getShiftRuleSelectedCaptain(int $id);
    public function getShiftRuleCaptainList(array $filters,int $shift_rule);
    public function updateShiftRule(int $id, array $data);
    public function assignShiftRuleCaptainList(array $filters,int $shiftRule,int $perPage);
    public function assignShiftRuleByCaptain(array $data);

}