<?php

namespace App\Services\General\SystemSetting;

use App\Interfaces\General\SystemSettingInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\CommissionRule;

class CommissionRuleService
{
    public function __construct(private readonly SystemSettingInterface $systemSettingInterface)
    {
    }

    public function getCommissionRuleList(array $filters, int $perPage)
    {
        return $this->systemSettingInterface->getCommissionRuleList($filters, $perPage);
    }

    public function createDeliveryBasedCommissionRule(array $validated)
    {
        $hasFallback = !empty($validated['has_fallback']) ? 1 : 0;

        $kilometers = [];
        foreach ($validated['from_km'] as $key => $value) {
            $kilometers[] = [
                "km_from" => $validated['from_km'][$key],
                "km_to" => $validated['to_km'][$key],
                "commission" => $validated['commission'][$key],
            ];
        }

        $mainData = [
            "name" => $validated["name"],
            "commission_type" => 1,
            'status' => (int)$validated['status'],
            "additional_km_setting" => $validated["additional_km_setting"] ?? 0,
            "extra_commission_above_km" => $validated["extra_commission_above_km"] ?? null,
            "extra_commission_per_km" => $validated["extra_commission_per_km"] ?? null,
            "compensation_applicable" => $validated["compensation_applicable"] ?? 0,
            "compensation_based_on" => $validated["compensation_based_on"] ?? null,
            "basic_commission_percentage_compensation" => $validated["basic_commission_percentage_compensation"] ?? null,
            "fixed_amount_compensation" => $validated["fixed_amount_compensation"] ?? null,
            "compensation_reached_destination_above_km" => $validated["compensation_reached_destination_above_km"] ?? null,
            "compensation_reached_destination_per_km" => $validated["compensation_reached_destination_per_km"] ?? null,
            "created_by" => auth()->id(),
            "has_fallback" => $hasFallback,
            "special_condition_type"  => $validated['special_condition_type'] ?? null,
            "fallback_hour" =>  $validated['fallback_hour_delivery'] ?? null,
            "fallback_acceptance" => $validated['fallback_acceptance_delivery'] ?? null,
            "fallback_hour_per_order" =>
                $validated['fallback_hour_per_order'] ??
                $validated['fallback_per_order'] ??
                null,
        ];

        return $this->systemSettingInterface->createDeliveryBasedCommissionRule([
            'main' => $mainData,
            'clients' => $validated['extra_commission_applicable_clients'] ?? [],
            'kilometers' => $kilometers,
        ]);
    }

    public function updateDeliveryBasedCommissionRule(array $validated,int $id)
    {
        $specialType = $validated['special_condition_type'] ?? null;

        if ($specialType === 'orders') {
            $fallbackPerOrder = $validated['fallback_per_order'] ?? null;
        } elseif ($specialType === 'daily') {
            $fallbackPerOrder = $validated['fallback_hour_per_order'] ?? null;
        } else {
            $fallbackPerOrder = null;
        }

        $mainData = [
            'name' => $validated['name'],
            'commission_type' => 1,
            'status' => (int)$validated['status'],

            'additional_km_setting' => $validated['additional_km_setting'] ?? 0,
            'extra_commission_above_km' => $validated['extra_commission_above_km'] ?? null,
            'extra_commission_per_km' => $validated['extra_commission_per_km'] ?? null,

            'compensation_applicable' => $validated['compensation_applicable'] ?? 0,
            'compensation_based_on' => $validated['compensation_based_on'] ?? null,
            'basic_commission_percentage_compensation' =>
                $validated['basic_commission_percentage_compensation'] ?? null,
            'fixed_amount_compensation' =>
                $validated['fixed_amount_compensation'] ?? null,

            'previous_edit_reason' => CommissionRule::find($id)->edit_reason,
            'previous_updated_by' => CommissionRule::find($id)->updated_by,
            'edit_reason' => $validated['edit_reason'],

            'special_condition_type' => $specialType,
            'fallback_hour' => $validated['fallback_hour_delivery'] ?? null,
            'fallback_acceptance' => $validated['fallback_acceptance_delivery'] ?? null,
            'fallback_hour_per_order' => $fallbackPerOrder,

            'has_fallback' => $validated['has_fallback'] ?? 0,
            'updated_by' => auth()->id(),
        ];

        $kilometers = collect($validated['from_km'])
            ->map(function ($value, $key) use ($validated) {
                return [
                    'km_from' => $validated['from_km'][$key],
                    'km_to' => $validated['to_km'][$key],
                    'commission' => $validated['commission'][$key],
                ];
            })->toArray();

        return $this->systemSettingInterface->updateDeliveryBasedCommissionRule($id, [
            'main' => $mainData,
            'clients' => $validated['extra_commission_applicable_clients'] ?? [],
            'kilometers' => $kilometers,
        ]);
    }

    public function createKplBasedCommissionRule(array $validated)
    {
        $hasFallback = $validated['has_fallback'] ?? false;
        $additionalKm = $validated['additional_km_setting'] ?? false;

        $mainData = [
            'name' => $validated['commission_rule_name'],
            'commission_type' => 2,
            'status' => (int)($validated['status'] ?? 1),

            'daily_fixed_value' => $validated['daily_fixed_value'],
            'hour_component_value' => $validated['hour_component_value'],
            'hour_component_distribution' => $validated['hour_percent'],
            'acceptance_component_value' => $validated['acceptance_component_value'],
            'acceptance_component_distribution' => $validated['acceptance_percent'],

            'has_fallback' => $hasFallback,
            'fallback_hour' => $hasFallback ? $validated['fallback_hour'] : null,
            'fallback_acceptance' => $hasFallback ? $validated['fallback_acceptance'] : null,

            'additional_km_setting' => $additionalKm,
            'extra_commission_above_km' =>
                $additionalKm ? $validated['extra_commission_above_km'] : null,
            'extra_commission_per_km' =>
                $additionalKm ? $validated['extra_commission_per_km'] : null,

            'created_by' => auth()->id(),
        ];

        $hourKpi = collect($validated['hour_kpi'])->map(function ($item) {
            return [
                'hours_from' => (float) str_replace(':', '.', $item['hours_from']),
                'hours_to' => (float) str_replace(':', '.', $item['hours_to']),
                'payable_percent' => $item['hour_payable_percent'],
                'payable_value' => $item['hour_payable_value'],
            ];
        })->toArray();

        $acceptanceKpi = collect($validated['acceptance_kpi'])->map(function ($item) {
            return [
                'rate_from' => $item['rate_from'],
                'rate_to' => $item['rate_to'],
                'payable_percent' => $item['acceptance_payable_percent'],
                'payable_value' => $item['acceptance_payable_value'],
            ];
        })->toArray();

        return $this->systemSettingInterface->createKplBasedCommissionRule([
            'main' => $mainData,
            'clients' => $validated['extra_commission_applicable_clients'] ?? [],
            'kilometers' => $validated['fallback_kilometers'] ?? [],
            'hour_kpi' => $hourKpi,
            'acceptance_kpi' => $acceptanceKpi,
        ]);
    }
    
    public function updateKplBasedCommissionRule(array $validated,$id)
    {
        $mainData = [
            'name' => $validated['commission_rule_name'],
            'commission_type' => 2,
            'status' => (int)($validated['status'] ?? 1),

            'daily_fixed_value' => $validated['daily_fixed_value'],
            'hour_component_value' => $validated['hour_component_value'],
            'hour_component_distribution' => $validated['hour_percent'],
            'acceptance_component_value' => $validated['acceptance_component_value'],
            'acceptance_component_distribution' => $validated['acceptance_percent'],

            'has_fallback' => $validated['has_fallback'] ?? false,
            'fallback_hour' => $validated['has_fallback'] ? $validated['fallback_hour'] : null,
            'fallback_acceptance' => $validated['has_fallback'] ? $validated['fallback_acceptance'] : null,

            'additional_km_setting' => $validated['additional_km_setting'] ?? false,
            'extra_commission_above_km' =>
                $validated['additional_km_setting'] ? $validated['extra_commission_above_km'] : null,
            'extra_commission_per_km' =>
                $validated['additional_km_setting'] ? $validated['extra_commission_per_km'] : null,

            'edit_reason' => $validated['edit_reason'] ?? null,
            'updated_by' => auth()->id(),
        ];

        $hourKpi = collect($validated['hour_kpi'])->map(function ($item) {
            return [
                'hours_from' => (float) str_replace(':', '.', $item['hours_from']),
                'hours_to' => (float) str_replace(':', '.', $item['hours_to']),
                'payable_percent' => $item['hour_payable_percent'],
                'payable_value' => $item['hour_payable_value'],
            ];
        })->toArray();

        $acceptanceKpi = collect($validated['acceptance_kpi'])->map(function ($item) {
            return [
                'rate_from' => $item['rate_from'],
                'rate_to' => $item['rate_to'],
                'payable_percent' => $item['acceptance_payable_percent'],
                'payable_value' => $item['acceptance_payable_value'],
            ];
        })->toArray();

        return $this->systemSettingInterface->updateKplBasedCommissionRule($id, [
            'main' => $mainData,
            'clients' => $validated['extra_commission_applicable_clients'] ?? [],
            'kilometers' => $validated['fallback_kilometers'] ?? [],
            'hour_kpi' => $hourKpi,
            'acceptance_kpi' => $acceptanceKpi,
        ]);
    }
    public function detailsCommissionRule(int $id)
    {
        return $this->systemSettingInterface->detailsCommissionRule($id);
    }
    public function detailsDeliveryBasedCommissionRule(int $id)
    {
        return $this->systemSettingInterface->detailsDeliveryBasedCommissionRule($id);
    }
    public function bulkCommissionRuleStatusUpdate(array $ids, int $status): array
    {
        $updatedCount = $this->systemSettingInterface->bulkCommissionRuleStatusUpdate($ids, $status);

        $statusText = $status == 1 ? 'activated' : 'deactivated';

        return [
            'updated_count' => $updatedCount,
            'status_text' => $statusText
        ];
    }
    public function addClientByCommissionRule(int $ruleId, array $data)
    {
        $clientIds = $data['clients'] ?? [];
        return $this->systemSettingInterface->addClientByCommissionRule($ruleId, $clientIds);
    }
    public function getCommissionRulesCaptain()
    {
        return $this->systemSettingInterface->getCommissionRulesCaptain();
    }
    public function commissionRulesCaptainDetails(array $filters,int $perPage)
    {
        return $this->systemSettingInterface->commissionRulesCaptainDetails($filters,$perPage);
    }
    public function addCaptainByCommissionRules(int $ruleId, array $data)
    {
        return $this->systemSettingInterface->addCaptainByCommissionRules($ruleId,$data['captains']);
    }


}