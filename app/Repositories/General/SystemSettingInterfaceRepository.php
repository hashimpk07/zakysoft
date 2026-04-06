<?php

namespace App\Repositories\General;
use App\Interfaces\General\SystemSettingInterface;

use Laravel\Passport\Token;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Industry;
use App\Vat;
use App\Role;
use App\Page;
use App\CommissionRule;
use App\Client;
use App\Captain;
use App\DispatchRule;
use App\DispatchNotificationPreference;
use App\ClientShop;
use App\ShiftRule;
use App\ShiftRuleSetting;
use App\ShiftRuleLog;
use App\DeliveryType;

class SystemSettingInterfaceRepository implements SystemSettingInterface
{
    public function getUserActiveTokens($user, int $perPage = 10)
    {
        return $user->tokens()
            ->where('revoked', false)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function getAllScopes()
    {
        return Passport::scopes();
    }

    public function removeToken(int $id): bool
    {
        $token = Token::findOrFail($id);
        return $token->delete();
    }

    public function createToken($user, string $name, array $scopes): string
    {
        return $user->createToken($name, $scopes)->accessToken;
    }

    public function createIndustry(array $data)
    {
        return Industry::create($data);
    }

    public function getIndustryList(array $filters, int $perPage)
    {
        return Industry::query()
            ->select('id', 'name')   
            ->when($filters['name'] ?? null, function ($query, $search) {
                $query->where('name', 'like', '%' . $search . '%');
            })
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function getIndustryDetails($id)
    {
        return Industry::findOrFail($id);
    }

    public function updateIndustry(int $id, array $data)
    {
        $industry = $this->getIndustryDetails($id);
        $industry->update($data);
        return $industry;
    }

    public function getVatList(array $filters, int $perPage)
    {
        return Vat::query()
            ->select('id', 'name','created_by','status','created_at','rate')   
            ->when($filters['name'] ?? null, function ($query, $search) {
                $query->where('name', 'like', '%' . $search . '%');
            })
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function createVat(array $data)
    {
        return Vat::create($data);
    }

    public function deactivateAllVat()
    {
        return Vat::query()->update(['status' => 'inactive']);
    }

    public function getVatDetails($id)
    {
        return Vat::query()->select(['id', 'name', 'rate','status'])->findOrFail($id);
    }

    public function updateVat(int $id, array $data)
    {
        $vat = $this->getVatDetails($id);
        $vat->update($data);
        return $vat;
    }
  
    public function deleteVat(int $id): bool
    {
        $vat = $this->getVatDetails($id);
        return $vat->delete();
    }

    public function updateVatStatus(int $id, int $updatedBy)
    {
        $vat = $this->getVatDetails($id);
        $vat->status = $vat->status === 'active' ? 'inactive' : 'active';
        $vat->updated_by = $updatedBy;
        $vat->save();
        return $vat;
    }

    public function getRoleList()
    {
        return Role::where('name' ,'!=', 'captain')->select(['id', 'name', 'display_name','guard_name'])->orderBy('name')->get();
    }

    public function createRole($data)
    {
        return Role::create($data);
    }

    public function getRoleWithPermissions(int $roleId)
    {
        $role = Role::with('permissions')->findOrFail($roleId);

        $pages = Page::query()
            ->parent()
            ->with([
                'children.permissions',
                'permissions'
            ])
            ->orderBy('priority')
            ->get();

        return [
            'role' => $role,
            'pages' => $pages
        ];
    }

    public function updatePermissions(int $roleId, array $permissions)
    {
        $role = Role::findOrFail($roleId);
        $role->syncPermissions($permissions);
        return $role->load('permissions');
    }
    
    public function deleteRole(int $roleId): bool
    {
        $role = Role::with('users')->findOrFail($roleId);
        if ($role->users()->count() > 0) {
            return false;
        }
        $role->delete();
        return true;
    }

    public function getCommissionRuleList(array $filters, int $perPage)
    {
        return CommissionRule::query()
        ->select([
            'id',
            'name',
            'status',
            'commission_type',
            'daily_fixed_value',
            'hour_component_distribution',
            'acceptance_component_distribution',
            'additional_km_setting',
            'extra_commission_above_km',
            'extra_commission_per_km',
        ])
        ->with([
            'kilometers:id,commission_rule_id,km_from,km_to,commission',
            'clients:id,code,user_id',
            'clients.user:id,name,email',
            'captains:id,code,user_id',
            'captains.user:id,name,email'
        ])
        ->when($filters['commission_type'] ?? null, function ($query, $type) {
            $query->where('commission_type', $type);
        })
        ->when($filters['client'] ?? null, function ($query, $client) {
            $query->whereHas('clients', function ($q) use ($client) {
                $q->whereHas('user', function ($sub) use ($client) {
                    $sub->where('name', 'like', "%{$client}%")
                        ->orWhere('email', 'like', "%{$client}%");
                })->orWhere('code', 'like', "%{$client}%");
            });
        })
        ->when($filters['captain'] ?? null, function ($query, $captain) {
            $query->whereHas('captains', function ($q) use ($captain) {
                $q->whereHas('user', function ($sub) use ($captain) {
                    $sub->where('name', 'like', "%{$captain}%")
                        ->orWhere('email', 'like', "%{$captain}%");
                })->orWhere('code', 'like', "%{$captain}%");
            });
        })
        ->paginate($perPage);
    }

    public function createDeliveryBasedCommissionRule(array $data) 
    {
        return DB::transaction(function () use ($data) {

            $commissionRule = CommissionRule::create($data['main']);

            if (!empty($data['clients'])) {
                $commissionRule->clients()->attach($data['clients']);
            }

            if (!empty($data['kilometers'])) {
                $commissionRule->kilometers()->createMany($data['kilometers']);
            }

            return $commissionRule->load(['clients','kilometers']);
        });
    }

    public function createKplBasedCommissionRule(array $data)
    {
        return DB::transaction(function () use ($data) {

            $rule = CommissionRule::create($data['main']);

            if (!empty($data['clients'])) {
                $rule->clients()->attach($data['clients']);
            }

            if (!empty($data['kilometers'])) {
                $rule->kilometers()->createMany($data['kilometers']);
            }

            if (!empty($data['hour_kpi'])) {
                $rule->hourCommitments()->createMany($data['hour_kpi']);
            }

            if (!empty($data['acceptance_kpi'])) {
                $rule->acceptanceRates()->createMany($data['acceptance_kpi']);
            }

            return $rule->load([
                'clients',
                'kilometers',
                'hourCommitments',
                'acceptanceRates'
            ]);
        });
    }

    public function updateKplBasedCommissionRule(int $id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $rule = CommissionRule::findOrFail($id);
            $rule->update($data['main']);
            $rule->clients()->sync($data['clients'] ?? []);

            // Kilometers
            $rule->kilometers()->delete();
            if (!empty($data['kilometers'])) {
                $rule->kilometers()->createMany($data['kilometers']);
            }

            // Hour KPI
            $rule->hourCommitments()->delete();
            if (!empty($data['hour_kpi'])) {
                $rule->hourCommitments()->createMany($data['hour_kpi']);
            }

            // Acceptance KPI
            $rule->acceptanceRates()->delete();
            if (!empty($data['acceptance_kpi'])) {
                $rule->acceptanceRates()->createMany($data['acceptance_kpi']);
            }

            return $rule->load([
                'clients',
                'kilometers',
                'hourCommitments',
                'acceptanceRates'
            ]);
        });
    }

    public function updateDeliveryBasedCommissionRule(int $id, array $data)
    {
         return DB::transaction(function () use ($id, $data) {

            $rule = CommissionRule::findOrFail($id);
            $rule->update($data['main']);
            $rule->clients()->sync($data['clients'] ?? []);

            // Kilometers
            $rule->kilometers()->delete();
            $rule->kilometers()->createMany($data['kilometers']);

            return $rule->load(['clients','kilometers']);
        });
    }

    public function detailsCommissionRule(int $id)
    {
        return CommissionRule::query()->select([
                'id',
                'name',
                'status',
                'commission_type',
                'additional_km_setting',
                'extra_commission_above_km',
                'extra_commission_per_km',
                'has_fallback',
                'fallback_hour',
                'fallback_acceptance',
                'fallback_hour_per_order',
                'compensation_applicable',
                'compensation_based_on',
                'basic_commission_percentage_compensation',
                'fixed_amount_compensation',
                'per_km_compensation',
                'compensation_reached_destination_above_km',
                'compensation_reached_destination_per_km',
            ])->with([
                'clients:id,user_id,code,email,mobile_number,status',
                'kilometers:id,commission_rule_id,km_from,km_to,commission,is_special_condition',
                'hourCommitments',
                'acceptanceRates',
                'orderVolumeSlabs'
            ])->findOrFail($id);
    }
    public function detailsDeliveryBasedCommissionRule(int $id)
    {
        return CommissionRule::query()
            ->withCount(['clients', 'captains']) 
            ->with([
                'clients.user:id,name,email',
                'captains.user:id,name,email',
                'kilometers:id,commission_rule_id,km_from,km_to,commission,is_special_condition',
                'hourCommitments:id,commission_rule_id,hours_from,hours_to,payable_percent,payable_value',
                'acceptanceRates:id,commission_rule_id,rate_from,rate_to,payable_percent,payable_value',
                'orderVolumeSlabs:id,commission_rule_id,order_from,order_to,bonus_percentage',
                'updatedBy:id,name',
                'previouslyUpdatedBy:id,name'
            ])
            ->findOrFail($id);
    }
    public function bulkCommissionRuleStatusUpdate(array $ids, int $status)
    {
        return CommissionRule::whereIn('id', $ids)->update(['status' => $status]);
    }

    public function addClientByCommissionRule(int $ruleId, array $clientIds)
    {
        $rule = CommissionRule::findOrFail($ruleId);
        $rule->clients()->sync($clientIds);
        return $rule->load('clients');
    }

    public function getCommissionRulesCaptain()
    {
        return Captain::commissionedCaptain()->with('user')->select('id','firstname')->orderBy('firstname')->get();
    }

    public function commissionRulesCaptainDetails( array $filters,$perPage)
    {
        $query = Captain::query()
            ->select('id', 'firstname', 'status', 'captain_employment_type_id','nationality_id')
            ->with(['region.quadrant'])
            ->when($filters['nationality'] ?? null, fn($q, $v) =>
                $q->where('nationality_id', $v)
            )
            ->when($filters['captain'] ?? null, fn($q, $v) =>
                $q->where('captains.id', $v)
            )
            ->when($filters['status'] ?? null, fn($q, $v) =>
                $q->where('captains.status', $v)
            )
            ->when($filters['job_type'] ?? null, fn($q, $v) =>
                $q->where('captain_employment_type_id', $v)
            );

        if (!empty($filters['region_id'])) {
            $query->whereHas('regions', fn($q) =>
                $q->where('regions.id', $filters['region_id'])
            );
        }

        if (!empty($filters['quadrant_id'])) {
            $query->whereHas('regions.quadrant', fn($q) =>
                $q->where('quadrants.id', $filters['quadrant_id'])
            );
        }

        if (!empty($filters['vehicle_type'])) {
            $query->whereHas('vehicle.vehicleType', fn($q) =>
                $q->where('id', $filters['vehicle_type'])
            );
        }

        if (!empty($filters['third_party_logistic_company'])) {
            $query->whereHas('company', fn($q) =>
                $q->where('third_party_logistic_companies.id', $filters['third_party_logistic_company'])
            );
        }

        return $query->paginate($perPage)->withQueryString();
    }
    public function addCaptainByCommissionRules(int $ruleId, array $captains)
    {
        $rule = CommissionRule::findOrFail($ruleId);

        $existingCaptains = $rule->captains()->pluck('id')->toArray();

        $captainsToAdd = array_diff($captains, $existingCaptains);
        $captainsToRemove = array_diff($existingCaptains, $captains);

        Captain::whereIn('id', $captainsToAdd)
            ->update(['commission_rule_id' => $ruleId]);

        Captain::whereIn('id', $captainsToRemove)
            ->update(['commission_rule_id' => null]);

        return $rule->load('captains');
    }

    public function getDispatchRuleList(array $filters,int $perPage)
    {
        $rule = $filters['dispatch_rule'] ?? null;
        $status = $filters['status'] ?? null;

        return DispatchRule::with('applicableTo')
            ->when($rule, function ($query) use ($rule) {
                return $query->whereIn('id', $rule);
            })
            ->when($status !== null, function ($query) use ($status) {
                return $query->where('status', $status);
            })
            ->filterData()
           ->paginate($perPage);
    }

    public function createDispatchRule(array $data)
    {
       return DB::transaction(function () use ($data) {

            $dispatchRule = DispatchRule::create([
                'name'                          => $data['name'],
                'can_create_order_folder'       => $data['can_create_order_folder'] ?? null,
                'no_of_orders_in_folder'        => $data['no_of_orders_in_folder'] ?? null,
                'radius_for_seed_orders'        => $data['radius_for_seed_orders'] ?? null,
                'waiting_time_from_first_order' => $data['waiting_time_from_first_order'] ?? null,
                'manual_attempts'               => $data['manual_attempts'] ?? null,
                'manual_time_limit'             => $data['manual_time_limit'] ?? null,
                'critical_radius'               => $data['critical_radius'] ?? null,
                'critical_waiting_time'         => $data['critical_waiting_time'] ?? null,
                'critical_assign_priority'      => $data['critical_assign_priority'] ?? null,
                'min_completed_order_count'     => $data['min_completed_order_count'] ?? null,
            ]);

            // applicable_to
            if(isset($data['applicable_to'])){
                $dispatchRule->applicableTo()->sync($data['applicable_to']);
            }

            // notification preferences
            if(isset($data['to_km'])){
                $rules = [];

                foreach ($data['to_km'] as $key => $to_km) {
                    $rules[] = new DispatchNotificationPreference([
                        'to_km' => $to_km,
                        'waiting_time' => $data['waiting_time'][$key]
                    ]);
                }

                if(count($rules)){
                    $dispatchRule->notificationPreference()->saveMany($rules);
                }
            }

            // day slots
            if(isset($data['day_slots'])){
                foreach ($data['day_slots'] as $day => $slots){

                    if(!isset($slots['from_time'])) continue;

                    foreach ($slots['from_time'] as $index => $fromTime){

                        $dispatchRule->daySlots()->create([
                            'day_of_week' => $day,
                            'from_time' => $fromTime,
                            'to_time' => $slots['to_time'][$index] ?? null
                        ]);
                    }
                }
            }

            // special slots
            if(isset($data['special_slots'])){
                foreach ($data['special_slots'] as $slot){

                    if(!isset($slot['date']) || !isset($slot['from_time'])) continue;

                    foreach ($slot['from_time'] as $index => $fromTime){

                        $dispatchRule->specialSlots()->create([
                            'date' => $slot['date'],
                            'from_time' => $fromTime,
                            'to_time' => $slot['to_time'][$index] ?? null
                        ]);
                    }
                }
            }

            return $dispatchRule->load([
                'applicableTo',
                'notificationPreference',
                'daySlots',
                'specialSlots'
            ]);
        });
    }
    public function getDispatchRuleDetails($id)
    {
        return DispatchRule::with(['applicableTo','notificationPreference','daySlots','specialSlots','criticalAssignPriority'])->findOrFail($id);
    }
    public function updateDispatchRule($dispatchRule, array $data)
    {
        DB::transaction(function () use ($dispatchRule, $data) {

            $dispatchRule->previous_updated_by = $dispatchRule->updated_by;
            $dispatchRule->previous_edit_reason = $dispatchRule->edit_reason;

            $dispatchRule->update([
                'name' => $data['name'],
                'can_create_order_folder' => $data['can_create_order_folder'] ?? null,
                'no_of_orders_in_folder' => $data['no_of_orders_in_folder'] ?? null,
                'radius_for_seed_orders' => $data['radius_for_seed_orders'] ?? null,
                'waiting_time_from_first_order' => $data['waiting_time_from_first_order'] ?? null,
                'manual_attempts' => $data['manual_attempts'] ?? null,
                'manual_time_limit' => $data['manual_time_limit'] ?? null,
                'critical_radius' => $data['critical_radius'] ?? null,
                'critical_waiting_time' => $data['critical_waiting_time'] ?? null,
                'critical_assign_priority' => $data['critical_assign_priority'] ?? null,
                'min_completed_order_count' => $data['min_completed_order_count'] ?? null,
                'edit_reason' => $data['edit_reason'],
                'updated_by' => auth()->id(),
            ]);

            $dispatchRule->applicableTo()->sync($data['applicable_to']);

            // notification preferences
            $dispatchRule->notificationPreference()->delete();

            $rules = [];

            foreach ($data['to_km'] as $key => $toKm) {

                $rules[] = new DispatchNotificationPreference([
                    'to_km' => $toKm,
                    'waiting_time' => $data['waiting_time'][$key] ?? 0
                ]);
            }

            $dispatchRule->notificationPreference()->saveMany($rules);

            // Day slots
            $dispatchRule->daySlots()->delete();

            if (!empty($data['day_slots'])) {

                foreach ($data['day_slots'] as $day => $slots) {

                    foreach ($slots['from_time'] ?? [] as $index => $fromTime) {

                        $dispatchRule->daySlots()->create([
                            'day_of_week' => $day,
                            'from_time' => $fromTime,
                            'to_time' => $slots['to_time'][$index] ?? null,
                        ]);
                    }
                }
            }

            // Special slots
            $dispatchRule->specialSlots()->delete();

            if (!empty($data['special_slots'])) {

                foreach ($data['special_slots'] as $slot) {

                    foreach ($slot['from_time'] ?? [] as $index => $fromTime) {

                        $dispatchRule->specialSlots()->create([
                            'date' => $slot['date'],
                            'from_time' => $fromTime,
                            'to_time' => $slot['to_time'][$index] ?? null,
                        ]);
                    }
                }
            }

        });

        return $dispatchRule->load([
            'notificationPreference',
            'daySlots',
            'specialSlots',
            'applicableTo'
        ]);
    }
    public function getDispatchRuleStoresList(int $dispatchRuleId,array $filters,int $perPage)
    {
        $clientNames     = $filters['clientNames'] ?? [];
        $storeNames      = $filters['storeNames'] ?? [];
        $storeAreas      = $filters['storeAreas'] ?? [];
    
        $storesQuery = ClientShop::with(['client.user', 'zone.region'])
            ->where('status', ClientShop::STATUS_ACTIVE)
            ->where(function ($query) use ($dispatchRuleId) {
                $query->where('express_auto_assign_rule_id', $dispatchRuleId)
                      ->orWhere('schedule_auto_assign_rule_id', $dispatchRuleId);
            });

        if (!empty($clientNames)) {
            $storesQuery->whereHas('client.user', function ($query) use ($clientNames) {
                $query->whereIn('name', $clientNames);
            });
        }

        if (!empty($storeNames)) {
            $storesQuery->whereIn('name', $storeNames);
        }

        if (!empty($storeAreas)) {
            $storesQuery->whereHas('zone.region', function ($query) use ($storeAreas) {
                $query->whereIn('name', $storeAreas);
            });
        }

        return $storesQuery->paginate($perPage);
    
    }

    public function dispatchRuleAssignStoresList(int $dispatchRuleId,array $filters,int $perPage)
    {
        $clientIds = $filters['clientIds'] ?? [];
        $storeIds = $filters['storeIds'] ?? [];

        $storesQuery = ClientShop::with([
                'deliveryTypes',
                'dispatchRuleForExpress',
                'dispatchRuleForSchedule'
            ])
            ->where('status', ClientShop::STATUS_ACTIVE)
            ->whereIn('client_id', $clientIds);

        if (!empty($storeIds)) {
            $storesQuery->whereIn('id', $storeIds);
        }

        $stores = $storesQuery->paginate($perPage);

        $dispatchRule = DispatchRule::with(['applicableTo'])
            ->find($dispatchRuleId);

        $storeList = ClientShop::where('status', ClientShop::STATUS_ACTIVE)
            ->whereIn('client_id', $clientIds)
            ->get(['id','name']);

        return [
            "stores" => $stores,
            "dispatchRule" => $dispatchRule,
            "storeList" => $storeList
        ];
    }

    public function createDispatchRuleAssignStores( $data)
    {
        $dispatchRule = $data['dispatch_rule'] ?? null;
        $storesData = $data['stores'] ?? [];
        $updatedStores = [];
        DB::beginTransaction();
        try {

            foreach ($storesData as $storeData) {
                $storeId = $storeData['store_id'] ?? null;
                if (!$storeId) {
                    continue;
                }
                $store = ClientShop::find($storeId);

                if (!$store) {
                    continue;
                }

                if (array_key_exists('express', $storeData)) {

                    if ((string)$storeData['express'] === '1') {
                        $store->express_auto_assign_rule_id = $dispatchRule;
                    } else {
                        if ($store->express_auto_assign_rule_id == $dispatchRule) {
                            $store->express_auto_assign_rule_id = null;
                        }
                    }
                }

                // SCHEDULED
                if (array_key_exists('scheduled', $storeData)) {

                    if ((string)$storeData['scheduled'] === '1') {
                        $store->schedule_auto_assign_rule_id = $dispatchRule;
                    } else {
                        if ($store->schedule_auto_assign_rule_id == $dispatchRule) {
                            $store->schedule_auto_assign_rule_id = null;
                        }
                    }
                }

                // AUTO ASSIGNABLE
                if (array_key_exists('auto_assignable', $storeData)) {
                    $store->auto_assignable = (string)$storeData['auto_assignable'] === '1' ? 1 : 0;
                }
                $store->save();
                $updatedStores[] = $store->id;
            }
            DB::commit();

            return [
                'rule_id' => $dispatchRule,
                'updated_stores' => $updatedStores
            ];

        } catch (\Throwable $e) {

            DB::rollBack();
            throw $e;
        }
    }

    public function dispatchAssignStoreValidation(array $data)
    {
        $store = ClientShop::with(['dispatchRuleForExpress','dispatchRuleForSchedule'])->findOrFail($data['store_id']);
        $dispatchRuleId = $data['dispatch_rule_id'];
        $checkBoxType = $data['check_box_type'];
        $result['conflict'] = false;
        $result['existing_rule_name'] = null;

        if ( $checkBoxType === 'express' &&
            $store->express_auto_assign_rule_id && $store->express_auto_assign_rule_id != $dispatchRuleId) {
                $result['conflict'] = true;
                $result['existing_rule_name'] = optional($store->dispatchRuleForExpress)->name;
        }
        if ( $checkBoxType === 'scheduled' &&
            $store->schedule_auto_assign_rule_id && $store->schedule_auto_assign_rule_id != $dispatchRuleId
        ) {
                $result['conflict'] = true;
                $result['existing_rule_name'] =  optional($store->dispatchRuleForSchedule)->name;
        }

        return  $result;

    }
    public function getShiftRuleList(array $data, int $perPage)
    {
        $ruleIds = $filters['dispatch_rule'] ?? null;

        return ShiftRule::query()->when($ruleIds, function ($query) use ($ruleIds) {

                if (is_array($ruleIds)) {
                    $query->whereIn('id', $ruleIds);
                } else {
                    $query->where('id', $ruleIds);
                }
            })->latest('id')
            ->paginate($perPage);
    }

    public function createShiftRule(array $data)
    {
        return DB::transaction(function () use ($data) {

            $shiftRule = ShiftRule::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => ShiftRule::STATUS_ACTIVE,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            $settings = [];

            foreach ($data['settings'] ?? [] as $day => $daySettings) {

                if (in_array($day, $data['day'] ?? [])) {

                    $settings[] = new ShiftRuleSetting([
                        'day' => $day,
                        'shift_a_start' => $daySettings['shift_a_start'] ?? null,
                        'shift_a_end' => $daySettings['shift_a_end'] ?? null,
                        'shift_b_start' => $daySettings['shift_b_start'] ?? null,
                        'shift_b_end' => $daySettings['shift_b_end'] ?? null,
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id()
                    ]);
                }
            }

            if (count($settings)) {
                $shiftRule->settings()->saveMany($settings);
            }

            ShiftRuleLog::create([
                'action' => 'Created',
                'created_by' => auth()->id(),
                'action_date' => now()->toDateString(),
                'action_time' => now()->format('H:i:s'),
                'shift_id' => $shiftRule->id
            ]);

            return $shiftRule->load('settings');
        });
    }
    public function getShiftRuleDetails(int $ruleId)
    {
        return ShiftRule::with('settings')->select('id','name','description','status')->find($ruleId);  
    }
    public function getShiftRuleLogsDetails(int $ruleId)
    {
        return ShiftRuleLog::select('id','action','created_by','created_at')->where('shift_id', $ruleId)
                ->with('user:id,name')
                ->get();
    }
    public function getShiftRuleSelectedCaptain(int $ruleId)
    {
        return Captain::with('user')->select('id','firstname')->where('shift_rule_id', $ruleId)->get();
    }

    public function getShiftRuleCaptainList(array $filter,int $ruleId)
    {
        $perPage = $filter['per_page'] ?? 10;
        $captainId = $filter['captain'] ?? null;

        return Captain::select('id', 'code', 'firstname', 'lastname','iqama_number','status','shift_rule_id','nationality_id')
                    ->with([
                        'user:id,name',
                        'shiftRule:id,name,created_at,created_by',
                        'shiftRule.createdBy:id,name',
                        'latestOrderReport:id,captain_id,final_status_at',
                        'nationality:id,name',
                        'regions:id,name,quadrant_id',
                        'regions.quadrant:id,name'
                    ])
                    ->where('shift_rule_id',$ruleId)
                    ->when($captainId, function ($query) use ($captainId) {
                        $query->where('id', $captainId);
                    })
                    ->paginate($perPage);
    
    }
    public function updateShiftRule($id,array $data)
    {
        $shiftRule = ShiftRule::findOrFail($id);
        $settings = $data['settings'] ?? [];
        foreach ($settings as $day => &$daySettings) {
            foreach (['shift_a_start','shift_a_end','shift_b_start','shift_b_end'] as $field) {
                if (!empty($daySettings[$field]) && strlen($daySettings[$field]) == 5) {
                    $daySettings[$field] .= ':00';
                }
            }
        }

        DB::transaction(function () use ($shiftRule,$data,$settings) {

            $shiftRule->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'updated_by' => auth()->id()
            ]);

            $shiftRule->settings()->delete();

            $newSettings = [];

            foreach ($settings as $day => $daySettings) {

                if (in_array($day,$data['day'])) {

                    $newSettings[] = new ShiftRuleSetting([
                        'day' => $day,
                        'shift_a_start' => $daySettings['shift_a_start'] ?? null,
                        'shift_a_end' => $daySettings['shift_a_end'] ?? null,
                        'shift_b_start' => $daySettings['shift_b_start'] ?? null,
                        'shift_b_end' => $daySettings['shift_b_end'] ?? null,
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id()
                    ]);
                }
            }

            if(count($newSettings)){
                $shiftRule->settings()->saveMany($newSettings);
            }

            ShiftRuleLog::create([
                'action' => 'Edited',
                'created_by' => auth()->id(),
                'action_date' => now()->toDateString(),
                'action_time' => now()->format('H:i:s'),
                'shift_id' => $shiftRule->id
            ]);
        });

        return $shiftRule->fresh();
    }

    public function assignShiftRuleCaptainList(array $filters,int $shiftRule,int $perPage)
    {
        $query = Captain::select(
                    'id',
                    'code',
                    'firstname',
                    'lastname',
                    'phone_number',
                    'status',
                    'shift_rule_id',
                    'region_id',
                    'type'
                )->with([
                    'user',
                    'employmentType',
                    'nationality',
                    'regions.quadrant',
                    'autoAssignPriority',
                    'shiftRule'
                ]);

            $query->excludeShiftRule($shiftRule);

            if (!empty($filters['code'])) {
                $query->where('code', 'like', '%' . $filters['code'] . '%');
            }
            if (!empty($filters['captain'])) {
                $query->where('id', $filters['captain']);
            }
            if (!empty($filters['employment_type'])) {
                $query->where('captain_employment_type_id', $filters['employment_type']);
            }
            if (!empty($filters['third_party_company_id'])) {
                $query->whereHas('captainThirdParty', function ($sub) use ($filters) {
                    $sub->where('third_party_logistic_company_id', $filters['third_party_company_id']);
                });
            }
            if (!empty($filters['nationality'])) {
                $query->where('nationality_id', $filters['nationality']);
            }
            if (!empty($filters['region_id'])) {
                $query->where('region_id', $filters['region_id']);
            }
            if (!empty($filters['quadrant_id'])) {
                $query->whereHas('regions.quadrant', function ($sub) use ($filters) {
                    $sub->where('id', $filters['quadrant_id']);
                });
            }
            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (!empty($filters['job_type'])) {
                $query->where('captain_employment_type_id', $filters['job_type']);
            }
        return $query->paginate($perPage);
    }

    public function assignShiftRuleByCaptain(array $data)
    {
        $shiftRuleId = $data['shift_rule'];
        $captainIds = $data['captain_ids'];

        DB::transaction(function () use ($shiftRuleId, $captainIds) {

            Captain::whereIn('id', $captainIds)->update([
                'shift_rule_id' => $shiftRuleId
            ]);

            $assignedAt = now();
            $assignedBy = auth()->id();

            $insertData = collect($captainIds)->map(function ($captainId) use ($shiftRuleId, $assignedBy, $assignedAt) {

                return [
                    'shift_rule_id' => $shiftRuleId,
                    'captain_id' => $captainId,
                    'assigned_by' => $assignedBy,
                    'assigned_at' => $assignedAt,
                    'created_at' => $assignedAt,
                    'updated_at' => $assignedAt
                ];

            })->toArray();

            DB::table('captain_shift_rule_log')->insert($insertData);
        });

        return [
            'shift_rule_id' => $shiftRuleId,
            'assigned_captains' => count($captainIds)
        ];
    }

}