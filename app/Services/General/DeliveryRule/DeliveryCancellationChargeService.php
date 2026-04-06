<?php
namespace App\Services\General\DeliveryRule;

use App\Interfaces\General\DeliveryRuleInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;


class DeliveryCancellationChargeService
{
    public function __construct(private readonly DeliveryRuleInterface $deliveryRuleInterface)
    {
    }

    public function getCancellationChargeRuleList(array $filter,int $perPage)
    {
        return $this->deliveryRuleInterface->getCancellationChargeRuleList($filter, $perPage);
    }

    public function getCancellationChargeRuleDetails(int $id)
    {
        $rule = $this->deliveryRuleInterface->findCancellationChargeById($id);
        $applicableStatuses = $this->deliveryRuleInterface->getACancellationChargeApplicableStatus($id);

        return [
            'rule' => $rule,
            'applicable_when_status_ids' => $applicableStatuses
        ];
    }

    public function createCancellationChargeRule(array $data)
    {
        return DB::transaction(function () use ($data) {

            $ruleData = [
                'name' => $data['name'],
                'based_on' => $data['based_on'],
                'fixed_amount' => 0,
                'percentage_of_base_delivery_charge' => 0,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ];

            if ($data['based_on'] == 'fixed_amount') {
                $ruleData['fixed_amount'] = $data['fixed_amount'];
            }

            if ($data['based_on'] == 'percentage_of_base_delivery_charge') {
                $ruleData['percentage_of_base_delivery_charge']
                    = $data['percentage_of_base_delivery_charge'];
            }

            $rule = $this->deliveryRuleInterface->createCancellationCharge($ruleData);

            $statuses = [];

            foreach ($data['applicable_when_status_id'] as $statusId) {
                $statuses[] = [
                    'delivery_cancellation_charge_id' => $rule->id,
                    'applicable_when_status_id' => $statusId
                ];
            }

            $this->deliveryRuleInterface->insertCancellationChargeApplicableStatus($statuses);

            return $rule;
        });
    }

    public function getCancellationChargeRuleOrder()
    {
        return $this->deliveryRuleInterface->getOrderStatuses();
    }

    public function updateReturnChargeRule(array $data, int $id)
    {
        return DB::transaction(function () use ($data, $id) {
            $updateData = [
                'name' => $data['name'],
                'based_on' => $data['based_on'],
                'fixed_amount' => 0,
                'percentage_of_base_delivery_charge' => 0,
                'updated_by' => auth()->id()
            ];

            if ($data['based_on'] == 'fixed_amount') {
                $updateData['fixed_amount'] = $data['fixed_amount'];
            }

            if ($data['based_on'] == 'percentage_of_base_delivery_charge') {
                $updateData['percentage_of_base_delivery_charge']
                    = $data['percentage_of_base_delivery_charge'];
            }
            $charge = $this->deliveryRuleInterface->updateCancellationCharge($id, $updateData);
            $this->deliveryRuleInterface->deleteCancellationChargeStatus($id);
            $statuses = [];
            foreach ($data['applicable_when_status_id'] as $statusId) {
                $statuses[] = [
                    'delivery_cancellation_charge_id' => $id,
                    'applicable_when_status_id' => $statusId
                ];
            }
            $this->deliveryRuleInterface->insertCancellationChargeApplicableStatus($statuses);
            return $charge;
        });
    }

    public function updateStatusCancellationChargeRule(int $id)
    {
        $rule = $this->deliveryRuleInterface->findCancellationChargeById($id);
        $status = $rule->status == 1 ? 0 : 1;
        $rule = $this->deliveryRuleInterface->updateCancellationCharge($id, ['status' => $status,'updated_by' => auth()->id()]);
       return ['id' => $rule->id, 'status' => $rule->status ? 1 : 0,'status_label' => $rule->status ? 'Active' : 'Inactive'];
    }
}