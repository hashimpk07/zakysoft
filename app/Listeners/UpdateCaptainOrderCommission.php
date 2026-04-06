<?php

namespace App\Listeners;

use App\Actions\CaptainReportCommissionCreatedAction;
use App\CaptainCommission;
use App\CaptainEmploymentType;
use App\CommissionRule;
use App\Events\OrderDeliveryFinish;
use App\Order;
use App\OrderStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
class UpdateCaptainOrderCommission implements ShouldQueue, ShouldBeUnique, ShouldDispatchAfterCommit
{
    use InteractsWithQueue;

    public function __construct(private CaptainReportCommissionCreatedAction $action)
    {
    }

    /**
     * Handle the event.
     *
     * @param  OrderDeliveryFinish  $event
     * @return void
     */
    public function handle(OrderDeliveryFinish $event)
    {
        // find adjacent orders
        $order = Order::with('shop', 'captain.commissionRule', 'captainCommission')->find($event->order['id']);
        $captain = $order->captain;
        $return_client_accepted = $order->status_id == OrderStatus::CLIENT_RETURN_ACCEPTED;    

        if (!$captain || !$captain->earningCommission() || !in_array($order->status_id, [OrderStatus::DELIVERED, OrderStatus::CLIENT_RETURN_ACCEPTED]) || $order->captainCommission) {
            return;
        }

        $commissionRule = $captain?->commissionRule;
        if (!$commissionRule || $commissionRule->commission_type != CommissionRule::DELIVERY_BASED_COMMISSION) {
            return;
        }

        if ($captain->captain_employment_type_id == CaptainEmploymentType::SPONSORED && !$captain->commissionRule) {
            return;
        }

        $pdo = DB::connection()->getPdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
        DB::transaction(function () use ($order, $captain, $return_client_accepted, $commissionRule) {
            [$base_commission, $additional_km, $additional_km_commission] = $this->findCommission($captain, $order);
            $commission_per_order = $base_commission + $additional_km_commission;

            if ($return_client_accepted && $commission_per_order == 0) {
                return;
            }

            $previous_balance = 0;

            if (
                $previous_commission_balance = CaptainCommission::where([
                    ['captain_id', $captain->id],
                ])->latest('id')->first()
            ) {
                $previous_balance = $previous_commission_balance->balance;
            }

            //Setting the date to the current date, if the time is before 6 AM, we set it to the previous day
            $date = now();
            if (Carbon::parse($date)->format('H:i:s') < '06:00:00') {
                $date = Carbon::parse($date)->subDay()->format('Y-m-d');
            }
            else {
                $date = Carbon::parse($date)->format('Y-m-d');
            }

            $balance = $previous_balance + $commission_per_order;
            $commission = $order->captainCommission()->create([
                'captain_id' => $order->captain_id,
                'basic_delivery_earnings' => round($base_commission, 2),
                'additional_km' => round($additional_km, 2),
                'additional_km_earning' => round($additional_km_commission, 2),
                'commission' => round($commission_per_order, 2),
                'balance' => round($balance, 2),
                'settled_amount' => null,
                'settled_at' => null,
                'settled_by' => null,
                'settlement_accepted_at' => null,
                'commission_rule_id' => $commissionRule->id,
                'commission_rule_type' => $commissionRule->commission_type,
                'date' => $date,
            ]);

            $this->action->execute($commission);
        });
    }

    public function findCommission($captain, $order)
    {
        $rule = $captain->commissionRule;
        $travel_distance = abs($order->shop_to_delivery_km); // 24
        $base_commission = 0;
        $additional_km = 0;
        $additional_km_commission = 0;

        $return_client_accepted = $order->status_id == OrderStatus::CLIENT_RETURN_ACCEPTED;

        if (!$rule && $return_client_accepted) {
            return [$base_commission, $additional_km, $additional_km_commission];
        }

        if (!$rule) {
            $base_commission = $captain->commission_per_order;
            return [$base_commission, $additional_km, $additional_km_commission];
        }

        $kilometer = $rule->kilometers()
            ->where(function ($query) use ($travel_distance) {
                $query->where([
                    ['km_from', '<=', $travel_distance],
                    ['km_to', '>', $travel_distance],
                ])
                    ->orWhere('km_to', '<', $travel_distance);
            })
            ->orderBy('km_to', 'desc')
            ->first();

        $base_commission = $kilometer->commission ?? 0;

        if ($return_client_accepted) {
            if (!$rule->compensation_applicable || !$order->isReachedDestination()) {
                return [0, $additional_km, $additional_km_commission];
            }

            [$base_commission, $additional_km, $additional_km_commission] = $this->findCompensation($rule, $order, $base_commission);

            return [$base_commission, $additional_km, $additional_km_commission];
        }

        $clients = $rule->clients;

        if ($kilometer && $rule->additional_km_setting && in_array($order->client_id, $clients->pluck('id')->toArray()) && $travel_distance > $rule->extra_commission_above_km) {
            $additional_km = $travel_distance - $kilometer->km_to;
            $additional_km_commission = $additional_km * $rule->extra_commission_per_km;
        }

        return [$base_commission, $additional_km, $additional_km_commission];
    }

    public function findCompensation($rule, $order, $base_commission)
    {
        $compensation = 0;
        $additional_compensation = 0;
        $additional_km = 0;
        $travel_distance = abs($order->shop_to_delivery_km); // 24

        if ($rule->compensation_based_on == CommissionRule::COMPENSATION_BASED_ON_FIXED) {
            $compensation = $rule->fixed_amount_compensation;
        }

        if ($rule->compensation_based_on == CommissionRule::COMPENSATION_BASED_ON_COMMISSION) {
            $compensation = round(($base_commission * $rule->basic_commission_percentage_compensation) / 100, 2);
        }

        if ($travel_distance > $rule->compensation_reached_destination_above_km) {
            $additional_km = $travel_distance - $rule->compensation_reached_destination_above_km;
            $additional_compensation = $additional_km * $rule->compensation_reached_destination_per_km;
        }

        return [$compensation, $additional_km, $additional_compensation];
    }
}
