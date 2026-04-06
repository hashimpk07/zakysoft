<?php

namespace App\Services;

use App\Captain;
use App\CommissionRule;
use App\CaptainWorkingLog;
use App\CommissionRuleKilometer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Order;
use App\OrderStatus;

class CommissionService
{
    public function calculate(Captain $captain, Carbon $date, CommissionRule $rule): array
    {
        try {
            $log = CaptainWorkingLog::where('captain_id', $captain->id)
                ->where('date', $date->toDateString())
                ->first();

            if (!$log) {
                Log::channel('commission')->warning("No log for captain #{$captain->id} on {$date->toDateString()}");
                return ['amount' => 0];
            }

            $hours = $log->seconds_worked / 3600;
            $received = $log->orders_received;
            $delivered = $log->orders_delivered;
            $accepted = $log->orders_accepted;
            $tryAccept = $log->orders_try_to_accept;
            $denominator = $accepted + $tryAccept;
            $acceptRate = $received > 0 ? ($denominator / $received) * 100 : 0;

            $acceptancePercent = $rule->acceptanceRates()
                ->where('rate_from', '<=', $acceptRate)
                ->where('rate_to', '>=', $acceptRate)
                ->value('payable_percent') ?? 0;


            // Rule : Special codition checked
            if ($rule->has_fallback == 1 && ($rule->fallback_hour >= $hours || $rule->fallback_acceptance >= $acceptRate)) {
                $businessDayStart = $date->copy()->setTime(6, 0, 0)->format('Y-m-d H:i:s');
                $businessDayEnd = $date->copy()->addDay()->setTime(5, 59, 59)->format('Y-m-d H:i:s');
                $businessDate = $date->copy()->setTime(6, 0, 0)->format('Y-m-d');

                Log::channel('commission')->info('businessDayStart', ['businessDayStart' => $businessDayStart]);
                Log::channel('commission')->info('businessDayEnd', ['businessDayEnd' => $businessDayEnd]);

                $orders = Order::with('captain')
                    ->where('captain_id', $captain->id)
                    ->whereIn('status_id', [OrderStatus::DELIVERED])
                    ->whereBetween('orders.delivery_date', [$businessDayStart, $businessDayEnd])
                    ->get();

                Log::channel('commission')->info('orders', ['orders' => $orders]);
                if ($orders->isEmpty()) {
                    return [
                        'amount' => 0,
                        'hour_percent' => null,
                        'hour_component_value' => null,
                        'acceptance_percent' => null,
                        'acceptance_component_value' => null,
                        'orders' => []
                    ];
                }

                $kilometer = $rule->kilometers()->orderBy('km_to', 'desc')->get();
                $totalCommission = 0;
                $aClients = $rule->clients->pluck('id')->toArray();
                $orderCommissions = [];
                // Each Order in particular date
                foreach ($orders as $order) {
                    $orderId = $order->id;
                    $shop_to_delivery_km = abs($order->shop_to_delivery_km);
                    $client = $order->client->id;

                    $orderCommission = 0;
                    $maxKmToCommission = null;
                    $maxKmTo = 0;
                    $matched = false;
                    $basicDeliveryCharge = 0;
                    $extraDistance = 0;
                    $extraDistanceEarning = 0;

                    foreach ($kilometer as $kilo) {
                        $kmFrom = (float) $kilo->km_from;
                        $kmTo = (float) $kilo->km_to;
                        if ($kmTo > $maxKmTo) {
                            $maxKmTo = $kmTo;
                            $maxKmToCommission = $kilo->commission;
                        }
                        if ($shop_to_delivery_km >= $kmFrom && $shop_to_delivery_km < $kmTo) {
                            $orderCommission = $kilo->commission;
                            $basicDeliveryCharge = $kilo->commission;
                            $matched = true;
                            break;
                        }

                        if ($rule->additional_km_setting == 1) {
                            if (in_array($client, $aClients)) {
                                $orderCommission = $rule->extra_commission_per_km;
                            }
                        }
                    }
                    if (!$matched && $shop_to_delivery_km > $maxKmTo) {
                        // Maximum kilometer reached,use the maximum commcission
                        $orderCommission = $maxKmToCommission;
                        // check if the client is in the special condition list and apply extra commission     
                        if ($rule->additional_km_setting == 1 && $shop_to_delivery_km > $rule->extra_commission_above_km) {
                            if (in_array($client, $aClients)) {
                                $extraDistance = $shop_to_delivery_km - $maxKmTo;
                                $orderCommission += $extraDistance * $rule->extra_commission_per_km;
                                $extraDistanceEarning = $extraDistance * $rule->extra_commission_per_km;
                            }
                        }
                    }
                    $totalCommission += $orderCommission;
                    $orderCommissions[] = [
                        'order_id' => $order->id,
                        'commission' => round($orderCommission, 2),
                        'basic_delivery_charge' => $basicDeliveryCharge,
                        'additional_km' => $extraDistance,
                        'additional_km_commission' => $extraDistanceEarning,
                        'business_date' => $businessDate
                    ];
                }
                return [
                    'amount' => round($totalCommission, 2),
                    'hour_percent' => null,
                    'hour_component_value' => null,
                    'acceptance_percent' => $acceptRate,
                    'acceptance_component_value' => null,
                    'orders' => $orderCommissions
                ];

            } else {

                $hourSetting = $rule->hourCommitments()
                    ->where('hours_from', '<=', $hours)
                    ->where('hours_to', '>=', $hours)
                    ->first();

                if (!$hourSetting) {
                    $hourSetting = $rule->hourCommitments()
                        ->where('hours_to', '<', $hours)
                        ->orderByDesc('hours_to')
                        ->first();
                }
                $hourPercent = $hourSetting->payable_percent;

                // Rule 1: full payout
                if ($received == 0 && $rule->hour_component_value > 0) {


                    return [
                        'amount' => round($rule->daily_fixed_value, 2),
                        'hour_percent' => $hourPercent,
                        'hour_component_value' => $rule->hour_component_value,
                        'acceptance_percent' => 100,
                        'acceptance_component_value' => $rule->acceptance_component_value
                    ];
                } else {
                    // Rule 2: received orders but none accepted
                    if ($received > 0 && ($accepted + $tryAccept) == 0) {
                        return ['amount' => 0];
                    }

                    $hourComponent = $rule->hour_component_value * ($hourPercent / 100);
                    $acceptanceComponent = $rule->acceptance_component_value * ($acceptancePercent / 100);
                    $total = $hourComponent + $acceptanceComponent;


                    return [
                        'amount' => round($total, 2),
                        'hour_percent' => $hourPercent,
                        'hour_component_value' => round($hourComponent, 2),
                        'acceptance_percent' => $acceptRate,
                        'acceptance_component_value' => round($acceptanceComponent, 2)
                    ];
                }
            }
        } catch (\Throwable $e) {
            return ['amount' => 0];
        }
    }
}

