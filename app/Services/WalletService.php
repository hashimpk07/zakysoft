<?php

namespace App\Services;

use Illuminate\Http\Request;
use Facades\App\Services\ApiService;
use Facades\App\Services\OrderStatusLog;
use App\Account;
use App\OrderPayment;
use App\Order;
use App\Captain;
use App\Events\OrderStatusChanged;
use App\OrderStatus;
use DB;
use App\Vat;
use Facades\App\Services\PrestashopOrders;
use Illuminate\Support\Facades\Hash;


class WalletService
{
    public function addWallet($request, $order_id)
    {
        try {
            $data = $this->getData($request, $order_id);
        } catch (\Exception $e) {
            return ['status' => false, 'message' =>  $e->getMessage()];
        }

        if ($data != 0 && $data != 1) {
            return ['status' => true, 'message' =>  "Successfully Completed Order"];
        } else {
            if ($data == 1) {
                return ['status' => false, 'message' => "Login Error..!Please Try Again..!"];
            } else {
                return ['status' => false, 'message' => "Given amount cannot be greaterthan payable amount..!"];
            }
        }
    }

    public function getData($request, $order_id)
    {

        $captain_id  = $request->captain_id;
        if ($captain_id != 0) {
            $order_id  = $order_id;
            $delivery_otp = $request->data['otp'] ?? null;
            $orderData = Order::with('client', 'deliveryCode')->where('id', $order_id)->first(); //dd($orderData->amount,$request->data['given_amount']);
            $total_amount = $orderData->amount;
            $delivery_charge = $orderData->delivery_charge;
            $amount = $orderData->client->delivery_charge_incl == 'Yes' ? $total_amount : $total_amount + $delivery_charge;


            if ($order_otp = $orderData->otpVerificationNeeded()) {
                if (!$delivery_otp) {
                    throw new \Exception("Delivery OTP is required to complete the order");
                }
                $validate_otp = Hash::check($delivery_otp, $order_otp->otp);

                if (!$validate_otp) {
                    throw new \Exception("Invalid OTP");
                }
            }
      
            $note           = $request->data['note'] ?? null;
            $posId          = null;
            $posAmount = (float) ($request->data['pos_amount'] ?? 0);
            $cashAmount = (float) ($request->data['cash'] ?? 0);    
            $paymentMode = OrderPayment::BY_BOTH; //default payment mode will be By both it will helps to prevent the null error 

            if ($posAmount > 0 && $cashAmount > 0) {
                $paymentMode = OrderPayment::BY_BOTH;
                $givenAmount = $posAmount + $cashAmount;
            } elseif ($posAmount > 0) {
                $paymentMode = OrderPayment::BY_POS;
                $givenAmount = $posAmount;
            } elseif ($cashAmount > 0) {
                $paymentMode = OrderPayment::BY_CASH;
                $givenAmount = $cashAmount;
            } else {
                $givenAmount = 0;
            }

            $captainData = Captain::where('id', $captain_id)->first();
            $paymentData = [
                'captain_id'     => $captain_id,
                'order_id'       => $order_id,
                'payment_mode'   => $paymentMode,
                'given_amount'   => $givenAmount,
                'pos_id'         => $posId,
                'pos_amount'     => $posAmount,
                'cash'           => $cashAmount,
                'note'           => $note,
                'created_by'     => $captainData->user_id,
            ];

            $payment = OrderPayment::create($paymentData)->id;
            if ($payment) {
            //     $vat = Vat::where('status', 'active')->orderBy('id', 'desc')->first();
            //     $orderStatus = Order::where('id', $order_id)->first();
              
            //     $orderStatus->update(['status_id' => OrderStatus::DELIVERED, 'vat_rate' => isset($vat) ? $vat->rate : 0]);
                // OrderStatusChanged::dispatch($orderStatus);
                // OrderStatusLog::log(OrderStatus::DELIVERED, $captain_id, $order_id);
                // PrestashopOrders::updatePrestashopOrderPaymentmode($order_id,$paymentMode,OrderStatus::DELIVERED);
                return 2;
            } else {
                return 1;
            }
        } else {
            return 1;
        }
    }
}
