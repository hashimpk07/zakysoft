<?php

namespace App\Interfaces\General;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use App\DeliveryChargeRule;


interface DeliveryRuleInterface
{
    // Return Charge Rule
    public function getReturnChargeRuleList(array $search,int $perPage);
    public function getReturnChargeRuleDetails(int $id);
    public function createReturnChargeRule(array $data);
    public function insertOrderPendingReasons(array $data);
    public function updateReturnChargeRule(int $id,array $data);
    public function deleteOrderPendingReasons(int $id);
    public function findReturnChargeRuleById(int $id);
    public function updateStatusReturnChargeRule(int $id, array $data);

    // Cancellation Charge Rule
    public function getCancellationChargeRuleList(array $search,int $perPage);
    public function findCancellationChargeById(int $id);
    public function getOrderStatuses();
    public function getACancellationChargeApplicableStatus(int $id);
    public function createCancellationCharge(array $data);
    public function insertCancellationChargeApplicableStatus(array $data);
    public function updateCancellationCharge(int $id, array $data);
    public function deleteCancellationChargeStatus(int $id);

    //Price Rule Master
    public function getDeliveryPriceRuleRulesList(array $search,int $perPage);
    public function createDeliveryRule(array $data);
    public function createDeliveryRulePrice(array $data);
    public function createDeliveryZonePrice(array $data);
    public function createDeliveryRadiusRule(array $data);
    public function getDeliveryPriceRuleDetails(int $id);
    public function findDeliveryRule(int $id);
    public function updateDeliveryRule(DeliveryChargeRule $rule, array $data);
    public function deleteDeliveryRulePrices(int $ruleId);
    public function createDeliveryRuleZone(array $data);
    public function createDeliveryExtraRule(array $data);
    public function findDeliveryZoneByName(string $name);
    public function getDeliveryRuleWithRelations(int $id);
    public function findDeliveryById(int $id);
    public function updateDeliveryStatus(DeliveryChargeRule $rule, int $userId);

}