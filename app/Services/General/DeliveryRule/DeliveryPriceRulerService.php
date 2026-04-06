<?php
namespace App\Services\General\DeliveryRule;

use App\Interfaces\General\DeliveryRuleInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Zone;

class DeliveryPriceRulerService
{
    public function __construct(private readonly DeliveryRuleInterface $deliveryRuleInterface)
    {
    }

    public function getPriceRuleList(array $data,int $perPage)
    {
        return $this->deliveryRuleInterface->getDeliveryPriceRuleRulesList($data, $perPage);
    }

    public function createPriceRule(array $data)
    {
        DB::beginTransaction();
        try 
        {
            $rule = $this->deliveryRuleInterface->createDeliveryRule([
                'name' => $data['name'],
                'delivery_charge_based_on' => $data['delivery_charge_based_on'],
                'created_by' => Auth::id(),
                'updated_by' => Auth::id()
            ]);
            if ($data['delivery_charge_based_on'] == 'zone') {

                foreach ($data['delivery_charge'] as $key => $charge) {
                    $price = $this->deliveryRuleInterface->createDeliveryRulePrice([
                        'delivery_charge_rule_id' => $rule->id,
                        'base_delivery_charge' => $charge
                    ]);
                    $zones = explode(',', $data['zones'][$key]);
                    foreach ($zones as $zoneName) {
                        $zone = Zone::where('name', trim($zoneName))->first();
                        if ($zone) {
                            $this->deliveryRuleInterface->createDeliveryZonePrice([
                                'delivery_charge_rule_price_id' => $price->id,
                                'zone_id' => $zone->id
                            ]);
                        }
                    }
                }
            }

            if ($data['delivery_charge_based_on'] == 'radius') {
                $price = $this->deliveryRuleInterface->createDeliveryRulePrice([
                    'delivery_charge_rule_id' => $rule->id,
                    'base_delivery_charge' => $data['base_price'],
                    'base_delivery_radius_kilometer' => $data['base_radius']
                ]);
                if (!empty($data['radius_from'])) {
                    foreach ($data['radius_from'] as $key => $from) {
                        $this->deliveryRuleInterface->createDeliveryRadiusRule([
                            'delivery_charge_rule_price_id' => $price->id,
                            'delivery_charge_radius_scheme' => $data['delivery_charge_radius_scheme'][$key] ?? null,
                            'from_kilometer' => $from,
                            'to_kilometer' => $data['radius_to'][$key] ?? null,
                            'charge' => $data['radius_charge_per_km'][$key] ?? 0
                        ]);
                    }
                }
            }
            DB::commit();
            return $rule;
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function getPriceRuleDetails(int $id)
    {
        return $this->deliveryRuleInterface->getDeliveryPriceRuleDetails($id);
    }

    public function updatePriceRule(array $data,int $id)
    {
        DB::beginTransaction();
        try {
            $rule = $this->deliveryRuleInterface->findDeliveryRule($id);

            $this->deliveryRuleInterface->updateDeliveryRule($rule, [
                'name' => $data['name'],
                'delivery_charge_based_on' => $data['delivery_charge_based_on'],
                'updated_by' => auth()->id()
            ]);

            $this->deliveryRuleInterface->deleteDeliveryRulePrices($rule->id);

            if ($data['delivery_charge_based_on'] == 'zone') {
                $this->handleDeliveryZoneRules($rule->id, $data);
            } elseif ($data['delivery_charge_based_on'] == 'radius') {
                $this->handleDeliveryRadiusRules($rule->id, $data);
            }
            DB::commit();
            return $this->deliveryRuleInterface->getDeliveryRuleWithRelations($rule->id);

        } catch (\Exception $e) {

            DB::rollBack();
            throw $e;
        }
    }
    private function handleDeliveryZoneRules($ruleId, $data)
    {
        foreach ($data['delivery_charge'] as $key => $charge) 
        {
            $price = $this->deliveryRuleInterface->createDeliveryRulePrice(['delivery_charge_rule_id' => $ruleId,'base_delivery_charge' => $charge]);

            $zones = explode(',', $data['zones'][$key]);

            foreach ($zones as $zoneName) 
            {
                $zone = $this->deliveryRuleInterface->findDeliveryZoneByName(trim($zoneName));
                if ($zone) {
                    $this->deliveryRuleInterface->createDeliveryRuleZone(['delivery_charge_rule_price_id' => $price->id,'zone_id' => $zone->id]);
                }
            }
        }
    }

    private function handleDeliveryRadiusRules($ruleId, $data)
    {
        $price = $this->deliveryRuleInterface->createDeliveryRulePrice([
            'delivery_charge_rule_id' => $ruleId,
            'base_delivery_charge' => $data['base_price'],
            'base_delivery_radius_kilometer' => $data['base_radius']
        ]);

        foreach ($data['radius_from'] as $key => $from) 
        {
            $this->deliveryRuleInterface->createDeliveryExtraRule([
                'delivery_charge_rule_price_id' => $price->id,
                'delivery_charge_radius_scheme' => $data['delivery_charge_radius_scheme'][$key],
                'from_kilometer' => $from,
                'to_kilometer' => $data['radius_to'][$key] ?? null,
                'charge' => $data['radius_charge_per_km'][$key] ?? 0
            ]);
        }
    }
    public function updateStatusPriceRule(int $id)
    {
        $rule = $this->deliveryRuleInterface->findDeliveryById($id);
        if (!$rule) {
            return false;
        }
        return $this->deliveryRuleInterface->updateDeliveryStatus($rule, Auth::id());
    }

}