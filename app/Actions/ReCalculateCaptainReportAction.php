<?php

namespace App\Actions;

use App\Captain;
use App\CaptainReport;
use App\Order;

class ReCalculateCaptainReportAction
{
    /**
     * Handle the captain report update.
     *
     * @param object $orderData
     * @return void
     */
    public function execute(Order $order)
    {
        //* Find the order and captain associated with this event
        $order_payable_balance = $order->captain->orderPayableBalance ?? null;

        // Fetch the previous captain using function
        $previousCaptainId = $order->previousCaptain();

        if (!$previousCaptainId) {
            return;
        }

        //* Get the latest CaptainReport for the captain, or null if it doesn't exist
        $captain_reports = CaptainReport::where('captain_id', $previousCaptainId)->latest()->first();

        //* Initialize values from previous report or set to 0 if none exists
        $attended_orders = $captain_reports->attended_orders ?? 0;
        $previous_total_payed_amount_from_leajlak = $captain_reports->total_payed_amount_from_leajlak ?? 0;
        $total_received_amount_from_leajlak = $captain_reports->total_received_amount_from_leajlak ?? 0;
        $previous_total_bill_amount = $captain_reports->total_received_amount_from_leajlak ?? 0;
        $previous_store_payments = $captain_reports->total_received_amount_from_leajlak ?? 0;
        $previous_cod = $captain_reports->total_received_amount_from_leajlak ?? 0;
        $previous_credited_to_leajlak = $captain_reports->total_received_amount_from_leajlak ?? 0;
        $previous_balance = $captain_reports->balance ?? 0;

        //* Update balance by decrement the previous balance and the balance from the latest payment
        $balance = ($order_payable_balance && isset($order_payable_balance->balance))
        ? ($previous_balance - $order_payable_balance->balance)
        : $previous_balance;


        //* Decrement the attended orders count
        $attended_orders -= 1;

        //* Re Calculate order-related amounts and substract
        $total_payed_amount_from_leajlak = $previous_total_payed_amount_from_leajlak - ($order->payment->given_amount ?? 0);
        $total_received_amount_from_leajlak = $previous_store_payments - ($order->shop_payment->amount ?? 0);
        $total_bill_amount = $previous_total_bill_amount - ($order->amount ?? 0);
        $store_payments = $previous_store_payments - ($order->shop_payment->amount ?? 0);
        $cod = $previous_cod - ($order->payment->cash ?? 0);
        $previous_credited_to_leajlak = $previous_credited_to_leajlak - ($order->payment->pos_amount ?? 0);

        //* Update or create the CaptainReport with the new values
        CaptainReport::updateOrCreate(
            ['captain_id' => $previousCaptainId],
            [
                'attended_orders' => $attended_orders,
                'balance' => $balance,
                'total_payed_amount_from_leajlak' => $total_payed_amount_from_leajlak,
                'total_received_amount_from_leajlak' => $total_received_amount_from_leajlak,
                'total_bill_amount' => $total_bill_amount,
                'store_payments' => $store_payments,
                'cod' => $cod,
                'credited_to_leajlak' => $previous_credited_to_leajlak
            ]
        );
    }
}
