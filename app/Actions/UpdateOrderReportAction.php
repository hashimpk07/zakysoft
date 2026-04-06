<?php

namespace App\Actions;

use App\Captain;
use App\Order;
use App\OrderReport;
use App\OrderStatus;
use App\Services\OrderReportProcessingService;
use Illuminate\Support\Facades\DB;

class UpdateOrderReportAction
{
    /**
     * Handle the captain report update.
     *
     * @param array $orderData
     * @return \App\OrderReport
     */
    public function execute(Order $order)
    {

        [$cancellation_reason, $canceled_by] = $this->getCancellationReason($order);
        [$cod_amount, $order_payment_mode] = $this->getPaymentDetails($order);

        $data = [
            "order_id" => $order->id,
            "captain_id" => $order->captain_id,
            "client_id" => $order->client_id,
            "status_id" => $order->status_id,
            "shop_id" => $order->shopname,
            "region_id" => null,
            "zone_id" => null,
            "shop_to_delivery_km" => $order->shop_to_delivery_km,
            "payment_mode" => null,
            "bill_amount" => null,
            "bd_earning" => null,
            "delivery_charge" => null,
            "cash" => null,
            "bank" => null,
            "vat_amount" => null,
            "debit" => null,
            "internal_delivery_charge" => null,
            "client_balance" => null,
            "captain_balance" => null,
            "delivery_date" => null,
            "delivery_payment_mode" => null,
            "on_time_payment" => null,
            "delivery_charge_incl" => null,
            "vat_incl" => null,
            "vat_rate" => null,
            "fast_delivery_amount" => null,
            "scheduled_delivery_amount" => null,
            "order_created_at" => $order->created_at,
            "assigned_by" => $this->getAssignedBy($order),
            "order_accepted_at" => $this->getOrderAcceptedAt($order),
            "start_ride_at" => $this->getStartRideAt($order),
            "reached_shop_at" => $this->getReachedShopAt($order),
            "order_picked_at" => $this->getPickedAt($order),
            "shipped_at" => $this->getShippedAt($order),
            "reached_dest_at" => $this->getReachedDestAt($order),
            "final_status_at" => $this->getFinalStatusAt($order),
            "ticket" => $this->hasTicket($order),
            "pending_ticket" => $this->hasPendingTicket($order),
            "client_ticket" => $this->hasClientTicket($order),
            "cancellation_reason" => $cancellation_reason,
            "canceled_by" => $canceled_by,
            "created_by" => $this->getOrderCreatedBy($order),
            "auto_assign_attempts" => $this->getAutoAssignAttempts($order),
            'captain_rule_id' => $this->getCaptainRuleId($order),
            'relocated_count' => $this->getRelocatedCount($order),
            'relocation_history' => $this->getRelocationHistory($order),
            'last_address' => $this->getLastAddress($order),
            'first_address' => $this->getFirstAddress($order),
            'cod_amount' => $cod_amount,
            'order_payment_mode' => $order_payment_mode,
        ];

        return $this->insert($data);
    }

    public function getRelocatedCount($order) {
        return $order->log()->whereIn('status_id', [OrderStatus::RELOCATED, OrderStatus::REROUTED])->count();
    }

    public function getRelocationHistory($order): ?string {
        $history = $order->addresses()
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn($addr) => "$addr->latitude,$addr->longitude")
            ->implode(' | ');

        return $history !== '' ? $history : null;
    }

    public function getLastAddress($order) {
        return $order->location;
    }

    public function getFirstAddress($order) {
        if (isset($order->meta['is_location_missing_initially']) && $order->meta['is_location_missing_initially']) {
            return 'N/A';
        }

        $address = $order->addresses()->oldest('id')->first();
        return $address ? "$address->latitude,$address->longitude" : $order->location;
    }

    public function getPaymentDetails($order) {
        $payment = $order->payment()->latest('id')->first();
        if ($payment) {
            return [
                (double) ($payment->cash ?? 0),
                $payment->payment_mode ?: $order->delivery_payment_mode
            ];
        }
        return [0, $order->delivery_payment_mode];
    }

    public function getAssignedBy($order) {
        return $order->log()->whereIn('status_id', [OrderStatus::ACCEPT, OrderStatus::ASSIGNED_BY])->latest('id')->first()->created_by ?? null;
    }

    public function getOrderCreatedBy($order) {
        return $order->log()->where('status_id', OrderStatus::NEW_ORDER)->latest('id')->first()->created_by ?? null;
    }

    public function getOrderAcceptedAt($order) {
        return $order->log()->where('status_id', OrderStatus::ACCEPT)->latest('id')->first()->created_at ?? null;
    }

    public function getStartRideAt($order) {
        return $order->log()->where('status_id', OrderStatus::START_RIDE)->latest('id')->first()->created_at ?? null;
    }

    public function getReachedShopAt($order) {
        return $order->log()->where('status_id', OrderStatus::REACHED_SHOP)->latest('id')->first()->created_at ?? null;
    }

    public function getPickedAt($order) {
        return $order->log()->where('status_id', OrderStatus::PICKED)->latest('id')->first()->created_at ?? null;
    }

    public function getShippedAt($order) {
        return $order->log()->where('status_id', OrderStatus::SHIPPED)->latest('id')->first()->created_at ?? null;
    }

    public function getReachedDestAt($order) {
        return $order->log()->where('status_id', OrderStatus::REACHED_DESTINATION)->latest('id')->first()->created_at ?? null;
    }

    public function getFinalStatusAt($order) {
        return $order->log()->latest('id')->first()->created_at ?? null;
    }

    public function hasTicket($order) {
        return $order
            ->tickets()
            ->select(DB::raw('GROUP_CONCAT(subject) as subject'))
            ->where('type', 1)
            ->first()->subject ?? null;
    }

    public function hasPendingTicket($order) {
        return $order
            ->tickets()
            ->select(DB::raw('GROUP_CONCAT(subject) as subject'))
            ->where('type', 2)
            ->first()->subject ?? null;
    }

    public function hasClientTicket($order) {
        return $order
            ->tickets()
            ->select(DB::raw('GROUP_CONCAT(subject) as subject'))
            ->where('type', 3)
            ->first()->subject ?? null;
    }

    public function getCancellationReason($order) {

        if(!in_array($order->status_id, [OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED])) {
            return ["", ""];
        }

        $log = $order->log()
            ->select(DB::raw('COALESCE(cancellation_reasons.reason, order_logs.note) as note'), 'order_logs.canceled_by as canceled_by')
            ->leftJoin('cancellation_reasons', 'cancellation_reasons.id', '=', 'order_logs.reason_id')
            ->whereIn('status_id', [OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED])
            ->latest('order_logs.id')
            ->first();

        return [
            $log->note ?? null,
            $log->canceled_by ?? null,
        ];
    }

    public function insert(array $data) 
    {
        return OrderReport::updateOrCreate(
            ['order_id' => $data['order_id']],
            $data
        );
    }

    public function getAutoAssignAttempts($order) {
        return $order->log()->where('status_id', OrderStatus::ASSIGN_ATTEMPTS)->count();
    }

    public function getCaptainRuleId($order) {
        return $order->captain ? $order->captain->commission_rule_id : null;
    }
}