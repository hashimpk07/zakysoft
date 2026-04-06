<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;
use App\Captain;
use App\Order;
use App\OrderStatus;
use App\CaptainWorkingLog;
use App\CaptainCommission;
use App\CaptainBonus;
use Illuminate\Support\Facades\Log;

class SpecialConditionCommissionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $date;

    public function __construct($date = null)
    {
        $this->date = $date ? Carbon::parse($date) : now()->subDay();
    }

    public function handle(): void
    {
        $date = $this->date;

        $businessDayStart = $date->copy()->setTime(6, 0, 0);
        $businessDayEnd   = $date->copy()->addDay()->setTime(5, 59, 59);

        $captains = Captain::whereHas('commissionRule', function ($query) {
            $query->where('commission_type', 1)->where('has_fallback', 1);
        })->get();

        foreach ($captains as $captain) {

            $rule = $captain->commissionRule;

            $log = CaptainWorkingLog::where('captain_id', $captain->id)
                ->where('date', $date->toDateString())
                ->first();

            if (!$log || !$rule) {
                Log::channel('commission')->warning('Missing log or rule', ['captain_id' => $captain->id]);
                continue;
            }

            // Working data
            $hours       = $log->seconds_worked / 3600;
            $delivered   = $log->orders_delivered;
            $received    = $log->orders_received;
            $accepted    = $log->orders_accepted;
            $tryAccept   = $log->orders_try_to_accept;
            $acceptRate  = $received > 0 ? (($accepted + $tryAccept) / $received) * 100 : 0;

            // Rule data
            $requiredHours       = (float) $rule->fallback_hour;
            $requiredAcceptRate  = (float) $rule->fallback_acceptance;
            $specialType         = $rule->special_condition_type;
            $commissionRuleId    = $rule->id;
            $baseAmount          = $rule->fallback_hour_per_order;

    
            if ($hours < $requiredHours || $acceptRate < $requiredAcceptRate) {
                continue;
            }

            // ---- Daily BONUS ----
            if ($specialType === 'daily') {

                $order = Order::where('captain_id', $captain->id)
                    ->where('status_id', OrderStatus::DELIVERED)
                    ->whereBetween('delivery_date', [$businessDayStart, $businessDayEnd])
                    ->latest('created_at')
                    ->first();

                if (!$order) {
                    continue;
                }

                $bonus = CaptainBonus::updateOrCreate(
                    ['captain_id' => $captain->id, 'bonus_date' => $date],
                    [
                        'amount' => $baseAmount,
                        'reason' => "Daily Bonus: {$acceptRate}% for {$delivered} orders in {$hours} hours",
                    ]
                );

                if ($bonus->wasRecentlyCreated) {

                    $commission = CaptainCommission::withoutGlobalScope('excludeKpi')
                        ->where([
                            'captain_id' => $captain->id,
                            'order_id'   => $order->id,
                        ])
                        ->first();

                    if ($commission) {
                        $commission->update([
                            'commission_rule_id'    => $commissionRuleId,
                            'commission_rule_type'  => CaptainCommission::DELIVERY_BASED_COMMISSION,
                            'balance'               => ($commission->balance ?? 0) + $baseAmount,
                        ]);
                    }
                }
            }

            // ---- ORDER BONUS ----
            if ($specialType === 'orders') {

                $orders = Order::where('captain_id', $captain->id)
                    ->where('status_id', OrderStatus::DELIVERED)
                    ->whereBetween('delivery_date', [$businessDayStart, $businessDayEnd])
                    ->orderBy('created_at', 'desc')
                    ->get();

                $ordersCount = $orders->count();
                if ($ordersCount === 0) {
                    continue;
                }

                $totalAmount = $ordersCount * $baseAmount;

                $bonus = CaptainBonus::updateOrCreate(
                    ['captain_id' => $captain->id, 'bonus_date' => $date],
                    [
                        'amount' => $totalAmount,
                        'reason' => "Order Bonus: {$acceptRate}% for {$delivered} orders in {$hours} hours",
                    ]
                );

                if ($bonus->wasRecentlyCreated) {

                    foreach ($orders as $order) {

                        $commission = CaptainCommission::withoutGlobalScope('excludeKpi')
                            ->where([
                                'captain_id' => $captain->id,
                                'order_id'   => $order->id,
                            ])
                            ->first();

                        if ($commission) {
                            $commission->update([
                                'commission_rule_id'    => $commissionRuleId,
                                'commission_rule_type'  => CaptainCommission::DELIVERY_BASED_COMMISSION,
                                'balance'               => ($commission->balance ?? 0) + $totalAmount,
                            ]);
                        }
                    }
                }

                Log::channel('commission')->info('Orders bonus applied', [
                    'captain_id' => $captain->id,
                    'orders' => $orders->pluck('id')
                ]);
            }
        }
    }
}