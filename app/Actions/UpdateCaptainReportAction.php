<?php

namespace App\Actions;

use App\CaptainReport;
use App\Order;

class UpdateCaptainReportAction
{
    /**
     * Handle the captain report update.
     *
     * @param array $orderData
     * @return void
     */
    public function execute(Order $order)
    {
        if (!$order->captain_id) {
            return;
        }

        $captain = $order->captain;
        $captain_commission = $order->captainCommission;
        $payment = $order->payment;
        $shop_payment = $order->shopPayment;

        //* Get the latest CaptainReport for the captain, or null if it doesn't exist
        $captain_reports = CaptainReport::where('captain_id', $captain->id)->latest()->first();

        //* Initialize values from previous report or set to 0 if none exists
        $attended_orders = $captain_reports->attended_orders ?? 0;
        $previous_total_payed_amount_from_leajlak = $captain_reports->total_payed_amount_from_leajlak ?? 0;
        $previous_total_received_amount_from_leajlak = $captain_reports->total_received_amount_from_leajlak ?? 0;
        $previous_total_bill_amount = $captain_reports->total_bill_amount ?? 0;
        $previous_store_payments = $captain_reports->store_payments ?? 0;
        $previous_cod = $captain_reports->cod ?? 0;
        $previous_credited_to_leajlak = $captain_reports->credited_to_leajlak ?? 0;

        //* Increment the attended orders count
        $attended_orders += 1;

        //* Calculate the values by adding to previous ones, only if payment exists
        $total_payed_amount_from_leajlak = ($payment && $payment->payment_mode == 'By Cash')
        ? ($payment->given_amount ?? 0) + $previous_total_payed_amount_from_leajlak
        : $previous_total_payed_amount_from_leajlak;

        //* Calculate store payments and total received amount if shop_payment exists
        $total_received_amount_from_leajlak = $shop_payment
        ? ($shop_payment->amount ?? 0) + $previous_total_received_amount_from_leajlak
        : $previous_total_received_amount_from_leajlak;

        $total_bill_amount = ($order->amount ?? 0) + $previous_total_bill_amount;
        $store_payments = $shop_payment
        ? ($shop_payment->amount ?? 0) + $previous_store_payments
        : $previous_store_payments;

        //* Calculate COD and amount credited to Leajlak
        $cod = 0;
        $credited_to_leajlak = 0;

        if ($payment) {
            if ($payment->payment_mode == 'By Cash' || $payment->payment_mode == 'Both') {
                $cod = ($payment->cash ?? 0) + $previous_cod;
            }

            if ($payment->payment_mode == 'By POS' || $payment->payment_mode == 'Both') {
                $credited_to_leajlak = ($payment->pos_amount ?? 0) + $previous_credited_to_leajlak;
            }
        } else {
            $cod = $previous_cod;
            $credited_to_leajlak = $previous_credited_to_leajlak;
        }

            //* Update or create the CaptainReport with the new values
        CaptainReport::updateOrCreate(
            ['captain_id' => $captain->id],
            [
                'attended_orders' => $attended_orders,
                'total_payed_amount_from_leajlak' => $total_payed_amount_from_leajlak,
                'total_received_amount_from_leajlak' => $total_received_amount_from_leajlak,
                'total_bill_amount' => $total_bill_amount,
                'store_payments' => $store_payments,
                'cod' => $cod,
                'credited_to_leajlak' => $credited_to_leajlak,
            ],
        );
    }

}
