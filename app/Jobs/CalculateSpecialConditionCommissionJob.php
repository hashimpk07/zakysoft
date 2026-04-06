<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable; 
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Captain;
use App\Order;
use App\CaptainCommission;
use App\CaptainWorkingLog;
 use App\CommissionRuleKilometer;


class CalculateSpecialConditionCommissionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $date;

    public function __construct($date = null)
    {
        $this->date = $date ? Carbon::parse($date) : now()->subDay();
    }

    public function handle()
    {
        $date = $this->date;
        Log::channel('commission')->info('Enter');
        $captains = Captain::whereHas('commissionRule', function ($query) {
            $query->where('commission_type', 1)->where('has_fallback', 1);
        })->with('commissionRule.kilometers')->get();

        foreach ($captains as $captain) {
            Log::channel('commission')->info('captain details', ['captain' => $captain]);

            $log = CaptainWorkingLog::where('captain_id', $captain->id)
                ->where('date', $date->toDateString())
                ->first();

            Log::channel('commission')->info('Log', ['log' => $log]);

            if (!$log || !$captain->commissionRule) {
                Log::channel('commission')->warning('Missing log or commission rule', ['captain_id' => $captain->id]);
                continue;
            }

            $commissionRule = $captain->commissionRule;
            $hours = $log->seconds_worked / 3600;
            $received = $log->orders_received;
            $delivered = $log->orders_delivered;
            $accepted = $log->orders_accepted;
            $tryAccept = $log->orders_try_to_accept;
            $acceptRate = $received > 0 ? (($accepted + $tryAccept) / $received) * 100 : 0;

            $fallback = $commissionRule->fallback_hour;
            $fallAccepted = $commissionRule->fallback_acceptance;
            $commissionId = $commissionRule->id;

            if ($fallback < $hours || $fallAccepted < $accepted) {
                $orders = Order::with('captain', 'captainCommission')
                    ->where('captain_id', $captain->id)
                    ->whereDate('delivery_date', $date)
                    ->get();

              
                $kilometers = CommissionRuleKilometer::where('commission_rule_id',$commissionId )->get();

                Log::channel('commission')->info('kilometers', ['kilometers' => $kilometers]);

                foreach ($orders as $order) {
                    $orderkilometer = $order->shop_to_delivery_km;
                    $orderId = $order->id;
                    Log::channel('commission')->info('orders_id', ['orderId' => $orderId]);
                    foreach ($kilometers as $km) {
                        if ($orderkilometer >= $km->km_from && $orderkilometer < $km->km_to) {
                            $commission = $km->commission;
                            Log::channel('commission')->info('Matched KM Range for Fallback Hour', [
                                'commission_rule_id' => $commissionRule->id,
                                'fallback_hour' => $fallback,
                                'km_range' => "{$km->km_from} - {$km->km_to}",
                                'commission' => $commission,
                                'login_hours' => $hours,
                            ]);

                            CaptainCommission::withoutGlobalScope('excludeKpi')->updateOrCreate(
                            [
                                'captain_id' => $captain->id,
                                'order_id' => $orderId,
                            ],
                            [
                                'commission_rule_id' => $commissionId,
                                'commission_rule_type' => 1,
                                'hour_percent' => $hours / 100 ?? null,
                                'hour_component_value' => null,
                                'acceptance_percent' => $acceptRate / 100 ?? null,
                                'acceptance_component_value' => null,
                                'commission' => $commission,
                                'balance' => $commission,
        
                            ]
                        );
                            break; // stop at first matched range
                        }
                    }
                }
            } else {
                Log::channel('commission')->info('No fallback triggered', [
                    'fallback' => $fallback,
                    'actual_hours' => $hours,
                    'accepted' => $accepted,
                ]);
            }
        }

        Log::info("Finished calculating special commission for {$date->toDateString()}");
    }
}
