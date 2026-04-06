<?php
namespace App\Services\General\DeliveryRule;

use App\Interfaces\General\DeliveryRuleInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;


class DeliveryReturnChargeService
{
    public function __construct(private readonly DeliveryRuleInterface $deliveryRuleInterface)
    {
    }

    public function getReturnChargeRuleList(array $filter,int $perPage)
    {
        return $this->deliveryRuleInterface->getReturnChargeRuleList($filter, $perPage);
    }

    public function getReturnChargeRuleDetails(int $id)
    {
        return $this->deliveryRuleInterface->getReturnChargeRuleDetails($id);
    }

    public function createReturnChargeRule(array $data)
    {
        return DB::transaction(function () use ($data) {
            $ruleData = [
                'name' => $data['name'],
                'based_on' => $data['based_on'],
                'fixed_amount' => 0,
                'percentage_of_base_delivery_charge' => 0,
                'percentage_of_total_delivery_charge' => 0,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ];

            if ($data['based_on'] == 'fixed_amount') {
                $ruleData['fixed_amount'] = $data['fixed_amount'];
            }

            if ($data['based_on'] == 'percentage_of_base_delivery_charge') {
                $ruleData['percentage_of_base_delivery_charge'] = $data['percentage_of_base_delivery_charge'];
            }

            if ($data['based_on'] == 'percentage_of_total_delivery_charge') {
                $ruleData['percentage_of_total_delivery_charge'] = $data['percentage_of_total_delivery_charge'];
            }

            $rule = $this->deliveryRuleInterface->createReturnChargeRule($ruleData);

            $reasons = [];

            foreach ($data['applicable_reason_id'] as $reasonId) {
                $reasons[] = [
                    'delivery_order_return_charge_id' => $rule->id,
                    'cancellation_reason_id' => $reasonId
                ];
            }

            $this->deliveryRuleInterface->insertOrderPendingReasons($reasons);
            return $rule;
        });
    }

    public function updateReturnChargeRule(array $data, int $id)
    {
        return DB::transaction(function () use ($data, $id) {
            $updateData = [
                'name' => $data['name'],
                'based_on' => $data['based_on'],
                'fixed_amount' => 0,
                'percentage_of_base_delivery_charge' => 0,
                'percentage_of_total_delivery_charge' => 0,
                'updated_by' => auth()->id()
            ];

            if ($data['based_on'] == 'fixed_amount') {
                $updateData['fixed_amount'] = $data['fixed_amount'];
            }

            if ($data['based_on'] == 'percentage_of_base_delivery_charge') {
                $updateData['percentage_of_base_delivery_charge'] = $data['percentage_of_base_delivery_charge'];
            }

            if ($data['based_on'] == 'percentage_of_total_delivery_charge') {
                $updateData['percentage_of_total_delivery_charge'] = $data['percentage_of_total_delivery_charge'];
            }

            $charge = $this->deliveryRuleInterface->updateReturnChargeRule($id, $updateData);
            $this->deliveryRuleInterface->deleteOrderPendingReasons($id);

            $reasons = [];

            foreach ($data['applicable_reason_id'] as $reason) {
                $reasons[] = [
                    'delivery_order_return_charge_id' => $id,
                    'cancellation_reason_id' => $reason
                ];
            }

            $this->deliveryRuleInterface->insertOrderPendingReasons($reasons);

            return $charge;
        });
    }
    public function updateStatusReturnChargeRule(int $id)
    {
        $rule = $this->deliveryRuleInterface->findReturnChargeRuleById($id);
        $status = $rule->status == 1 ? 0 : 1;
        $rule = $this->deliveryRuleInterface->updateStatusReturnChargeRule($id, ['status' => $status,'updated_by' => auth()->id()]);
       return ['id' => $rule->id, 'status' => $rule->status ? 1 : 0,'status_label' => $rule->status ? 'Active' : 'Inactive'];
    }

}
