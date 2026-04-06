<?php

namespace App\Repositories\General;
use App\Interfaces\General\DeliveryRuleInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

use App\DeliveryOrderReturnCharge;
use App\CancellationReasonDeliveryOrderReturnCharge;
use App\DeliveryCancellationCharge;
use App\CancellationChargeApplicableStatus;
use App\OrderStatus;
use App\DeliveryChargeRule;
use App\DeliveryChargeRulePrice;
use App\Zone;
use App\DeliveryChargeRulePriceZone;
use App\DeliveryChargeRulePriceExtraRule;

use Illuminate\Support\Collection;

class DeliveryRuleInterfaceRepository implements DeliveryRuleInterface
{
    public function getReturnChargeRuleList(array $filters, int $perPage)
    {
        return DeliveryOrderReturnCharge::select('id','name','created_by','created_at','status')->with(['user:id,email'])
                ->when($filters['name'] ?? null, function ($query, $name) {
                    $query->where('name', 'like', $name . '%');
                })->paginate($perPage);
    }

    public function getReturnChargeRuleDetails($id)
    {
        $rule = DeliveryOrderReturnCharge::select(
                    'id',
                    'name',
                    'based_on',
                    'fixed_amount',
                    'percentage_of_base_delivery_charge',
                    'percentage_of_total_delivery_charge',
                    'status',
                    'created_at'
                )
                ->with(['user:id,email'])
                ->findOrFail($id);

        $selectedReasons = CancellationReasonDeliveryOrderReturnCharge
                ::where('delivery_order_return_charge_id', $id)
                ->pluck('cancellation_reason_id')
                ->toArray();

        $rule->selected_reasons = $selectedReasons;

        return $rule;
    }
    public function createReturnChargeRule(array $data)
    {
        return DeliveryOrderReturnCharge::create($data);
    }
    public function insertOrderPendingReasons(array $reasons)
    {
        return CancellationReasonDeliveryOrderReturnCharge::insert($reasons);
    }
    public function findReturnChargeRuleById(int $id)
    {
        return DeliveryOrderReturnCharge::findOrFail($id);
    }
    public function updateReturnChargeRule(int $id, array $data)    
    {
        $charge = DeliveryOrderReturnCharge::findOrFail($id);
        $charge->update($data);
        return $charge;
    }
    public function deleteOrderPendingReasons(int $id)
    {
        return CancellationReasonDeliveryOrderReturnCharge::where('delivery_order_return_charge_id',$id)->delete();
    }

    public function updateStatusReturnChargeRule(int $id, array $data)
    {
        $rule = DeliveryOrderReturnCharge::findOrFail($id);
        $rule->update($data);
        return $rule;
    }

    public function getCancellationChargeRuleList(array $filters, int $perPage)
    {
        return DeliveryCancellationCharge::select('id','name','created_by','created_at','updated_by','status')->with(['user'])
                ->when($filters['name'] ?? null, function ($query, $name) {
                    $query->where('name', 'like', $name . '%');
                })->paginate($perPage);

    }

    public function findCancellationChargeById(int $id)
    {
        return DeliveryCancellationCharge::findOrFail($id);
    }

    public function getACancellationChargeApplicableStatus(int $id)
    {
        return CancellationChargeApplicableStatus::
        where('delivery_cancellation_charge_id',$id)
        ->pluck('applicable_when_status_id')
        ->toArray();
    }

    public function getOrderStatuses()
    {
        return OrderStatus::whereIn('id',[
            OrderStatus::NEW_ORDER,
            OrderStatus::ACCEPT,
            OrderStatus::START_RIDE,
            OrderStatus::REACHED_SHOP
        ])
        ->select('id','name')
        ->get();
    }
    public function createCancellationCharge(array $data)
    {
        return DeliveryCancellationCharge::create($data);
    }

    public function insertCancellationChargeApplicableStatus(array $data)
    {
        return CancellationChargeApplicableStatus::insert($data);
    }

    public function updateCancellationCharge(int $id, array $data)
    {
        $charge = DeliveryCancellationCharge::findOrFail($id);
        $charge->update($data);
        return $charge;
    }

    public function deleteCancellationChargeStatus(int $id)
    {
        return CancellationChargeApplicableStatus::where(
            'delivery_cancellation_charge_id',
            $id
        )->delete();
    }

    public function insertStatuses(array $data)
    {
        return CancellationChargeApplicableStatus::insert($data);
    }

    public function getDeliveryPriceRuleRulesList(array $filters, int $perPage)
    {
        return DeliveryChargeRule::with(['user'])
            ->when($filters['name'] ?? null, function ($query, $name) {
                $query->where('name', 'like', $name . '%');
            })
            ->paginate($perPage);
    }

    public function createDeliveryRule(array $data)
    {
        return DeliveryChargeRule::create($data);
    }
    public function createDeliveryRulePrice(array $data)
    {
        return DeliveryChargeRulePrice::create($data);
    }
    public function createDeliveryZonePrice(array $data)
    {
        return DeliveryChargeRulePriceZone::create($data);
    }
    public function createDeliveryRadiusRule(array $data)
    {
        return DeliveryChargeRulePriceExtraRule::create($data);
    }
    public function getDeliveryPriceRuleDetails(int $id)
    {
        return DeliveryChargeRule::select('id','name','delivery_charge_based_on')
            ->with([
                'rulePrice:id,delivery_charge_rule_id,base_delivery_charge,base_delivery_radius_kilometer',
                'rulePrice.priceZones:id,delivery_charge_rule_price_id,zone_id',
                'rulePrice.priceZones.zone:id,name',
                'rulePrice.extraRules:id,delivery_charge_rule_price_id,delivery_charge_radius_scheme,from_kilometer,to_kilometer,charge'
            ])->find($id);
    }


/* ============================ */

    public function findDeliveryRule($id)
    {
        return DeliveryChargeRule::findOrFail($id);
    }

    public function updateDeliveryRule($rule, $data)
    {
        return $rule->update($data);
    }

    public function deleteDeliveryRulePrices($ruleId)
    {
        $prices = DeliveryChargeRulePrice::where('delivery_charge_rule_id',$ruleId)->get();

        foreach ($prices as $price) {

            DeliveryChargeRulePriceZone::where(
                'delivery_charge_rule_price_id',
                $price->id
            )->delete();

            DeliveryChargeRulePriceExtraRule::where(
                'delivery_charge_rule_price_id',
                $price->id
            )->delete();

            $price->delete();
        }
    }

   

    public function createDeliveryRuleZone(array $data)
    {
        return DeliveryChargeRulePriceZone::create($data);
    }

    public function createDeliveryExtraRule(array $data)
    {
        return DeliveryChargeRulePriceExtraRule::create($data);
    }

    public function findDeliveryZoneByName($name)
    {
        return Zone::where('name',$name)->first();
    }

    public function getDeliveryRuleWithRelations($id)
    {
        return DeliveryChargeRule::with(
            'rulePrice.priceZones.zone',
            'rulePrice.extraRules'
        )->find($id);
    }

    public function findDeliveryById(int $id)
    {
        return DeliveryChargeRule::find($id);
    }

    public function updateDeliveryStatus(DeliveryChargeRule $rule, int $userId)
    {
        $rule->status = ($rule->status == 0) ? 1 : 0;
        $rule->updated_by = $userId;
        return $rule->save();
    }

  
}