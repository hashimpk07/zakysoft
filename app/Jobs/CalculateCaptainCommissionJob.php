<?php

namespace App\Jobs;

use App\Captain;
use App\CaptainCommission;
use App\Services\CommissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CalculateCaptainCommissionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Captain $captain, public Carbon $date)
    {
    }

    public function handle(CommissionService $service)
    {
        try {
            $rule = $this->captain->commissionRule;

            if (!$rule) {
                Log::channel('commission')->info("No commission rule for Captain #{$this->captain->id}");
                return;
            }
            $result = $service->calculate($this->captain, $this->date, $rule);

            $previous_balance = 0;

            if (
                $previous_commission_balance = CaptainCommission::where([
                    ['captain_id', $this->captain->id],
                ])->latest('id')->first()
            ) {
                $previous_balance = $previous_commission_balance->balance;
            }

            $balance = $previous_balance + $result['amount'];

            Log::channel('commission')->info("balance{$balance}");

            // If we have individual order data (from fallback condition)
            if (isset($result['orders']) && !empty($result['orders'])) {
                foreach ($result['orders'] as $orderData) {
                    CaptainCommission::withoutGlobalScope('excludeKpi')->updateOrCreate([
                        'captain_id' => $this->captain->id,
                        'date' => $this->date->toDateString(),
                        'order_id' => $orderData['order_id'],
                    ], [
                        'commission_rule_id' => $rule->id,
                        'commission_rule_type' => 1,
                        'hour_percent' => $result['hour_percent'] ?? null,
                        'hour_component_value' => $result['hour_component_value'] ?? null,
                        'acceptance_percent' => $result['acceptance_percent'] ?? null,
                        'acceptance_component_value' => $result['acceptance_component_value'] ?? null,
                        'commission' => $orderData['commission'],
                        'balance' => $balance,
                        'basic_delivery_earnings' => $orderData['basic_delivery_charge'],
                        'additional_km' => $orderData['additional_km'],
                        'additional_km_earning' => $orderData['additional_km_commission'],
                    ]);
                }
            } else {
                // Store the KPI-based commission (as before)
                CaptainCommission::withoutGlobalScope('excludeKpi')->updateOrCreate([
                    'captain_id' => $this->captain->id,
                    'date' => $this->date->toDateString(),
                    'order_id' => CaptainCommission::KPI_ORDER_PLACEHOLDER,
                ], [
                    'commission_rule_id' => $rule->id,
                    'commission_rule_type' => 2,
                    'hour_percent' => $result['hour_percent'] ?? null,
                    'hour_component_value' => $result['hour_component_value'] ?? null,
                    'acceptance_percent' => $result['acceptance_percent'] ?? null,
                    'acceptance_component_value' => $result['acceptance_component_value'] ?? null,
                    'commission' => $result['amount'],
                    'balance' => $balance,
                ]);
            }



        } catch (\Throwable $e) {
            Log::channel('commission')->error('Error updating captain commission', [
                'captain_id' => $this->captain->id,
                'date' => $this->date->toDateString(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        Log::channel('commission')->info("Stored KPI commission for Captain #{$this->captain->id} on {$this->date->toDateString()}");
        return;
    }
}
