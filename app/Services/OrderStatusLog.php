<?php

namespace App\Services;

use App\CancellationReason;
use App\OrderLog;
use App\Order;
use Prestashop;
use Log;
use Auth;
use App\Captain;
use App\Jobs\FCMSend;
use App\OrderStatus;
use App\Services\Adapters\Clients\Farawlah;
use DB;
use Illuminate\Support\Facades\Log as FacadesLog;
use App\OrderPendingReason;

class OrderStatusLog
{

    public function log($status, $captain_id, $order_id, $reason_id = null, $note = null, $canceled_by = null, $user = null)
    {
        if (!$user) {
            if ($captain_id) {
                $captainData = Captain::where('id', $captain_id)->first();
                $user = $captainData->user_id;
            } else {
                $user = Auth::id();
                if ($user == null) {
                    $user = 1;
                }
            }
        }

        if ($reason = CancellationReason::where('reason', $note)->first()) {
            $canceled_by = $reason->is_caused_by_4u ? '4u' : 'client';
        }

        $order = Order::find($order_id);

        
        $OrderPendingReason = OrderPendingReason::where('id', $reason_id)->first();
        if ($reason_id == OrderPendingReason::REASON_FOR_REROUTE) {
            $reason_name = $OrderPendingReason ? $OrderPendingReason->name : null;
        } elseif ($status == OrderStatus::REQUEST_FOR_CANCEL) {
            $client_cancellation_reason_id = CancellationReason::ORDER_CANCELLED_BY_CLIENT;
            $reason_name = CancellationReason::ORDER_CANCELLED_BY_CLIENT_BRANCH_REASON;
        } else {
            $reason_name = null;
        }

        $log = OrderLog::create([
            'order_id' => $order_id,
            'captain_id' => $captain_id ?? ($order ? $order->captain_id : null),
            'status_id' => $status,
            'note' => $note ?? $reason_name,
            'reason_id' => $client_cancellation_reason_id ?? $reason_id,
            'canceled_by' => $canceled_by,
            'created_by' => $user
        ]);
        if ($order) {
            $order->update(['delivery_date' => date('Y-m-d H:i:s')]);
        }
        return $log;
    }

    public function updatePrestashopOrderStatus($order_id, $status)
    {
        try {
            //update order status to prestashop
            $orderData = Order::where('id', $order_id)->first();
            if ($orderData->client_order_id && $orderData->client_id == 1 && ($status == "Shipped" || $status == "Delivered" || $status == "Order Accept")) {
                if ($status == "Shipped") {
                    $pstatus = 4;
                }
                if ($status == "Delivered") {
                    $pstatus = 5;
                }
                if ($status == "Order Accept") {
                    $pstatus = 18;
                } /*else if($status == "Canceled") {
                   $pstatus = 6;
               }*/

                $opt = array(
                    'resource' => 'orders',
                    'display' => '[id,id_address_delivery,id_address_invoice,id_cart,id_currency,id_lang,id_customer,id_carrier,current_state,module,invoice_number,invoice_date,delivery_number,delivery_date,valid,date_add,date_upd,shipping_number,id_shop_group,id_shop,secure_key,payment,recyclable,gift,gift_message,mobile_theme,total_discounts,total_discounts_tax_incl,total_discounts_tax_excl,total_paid,total_paid_tax_incl,total_paid_tax_excl,total_paid_real,total_products,total_products_wt,total_shipping,total_shipping_tax_incl,total_shipping_tax_excl,carrier_tax_rate,total_wrapping,total_wrapping_tax_incl,total_wrapping_tax_excl,round_mode,round_type,conversion_rate,schedule_date,distance,order_end,reference,remark,Delivery_Time_ID,shop_actual_id,printed]',
                    'filter[id]' => (int) $orderData->client_order_id,
                    'id_shop' => '',

                );

                $xml = Prestashop::get($opt);
                $resources = $xml->children()->children();
                $resources->order->id = $orderData->client_order_id;
                $resources->order->current_state = $pstatus;
                $xml = simplexml_load_string(
                    '<?xml version="1.0" encoding="UTF-8"?>
                    <prestashop xmlns:xlink="http://www.w3.org/1999/xlink">
                       <order>
                            <id><![CDATA[' . (int) $orderData->client_order_id . ']]></id>
                            <id_address_delivery xlink:href="https://185.205.210.10/prestashop-dev/api/addresses/52192"><![CDATA[' . $resources->order->id_address_delivery . ']]></id_address_delivery>
                            <id_address_invoice xlink:href="https://185.205.210.10/prestashop-dev/api/addresses/52192"><![CDATA[' . $resources->order->id_address_invoice . ']]></id_address_invoice>
                            <id_cart xlink:href="https://185.205.210.10/prestashop-dev/api/carts/54782"><![CDATA[' . $resources->order->id_cart . ']]></id_cart>
                            <id_currency xlink:href="https://185.205.210.10/prestashop-dev/api/currencies/2"><![CDATA[' . $resources->order->id_currency . ']]></id_currency>
                            <id_lang xlink:href="https://185.205.210.10/prestashop-dev/api/languages/1"><![CDATA[' . $resources->order->id_lang . ']]></id_lang>
                            <id_customer xlink:href="https://185.205.210.10/prestashop-dev/api/customers/33719"><![CDATA[' . $resources->order->id_customer . ']]></id_customer>
                            <id_carrier xlink:href="https://185.205.210.10/prestashop-dev/api/carriers/287"><![CDATA[' . $resources->order->id_carrier . ']]></id_carrier>
                            <current_state xlink:href="https://185.205.210.10/prestashop-dev/api/order_states/19"><![CDATA[' . $pstatus . ']]></current_state>
                            <module><![CDATA[' . $resources->order->module . ']]></module>
                            <invoice_number><![CDATA[' . $resources->order->invoice_number . ']]></invoice_number>
                            <invoice_date><![CDATA[' . $resources->order->invoice_date . ']]></invoice_date>
                            <delivery_number><![CDATA[' . $resources->order->delivery_number . ']]></delivery_number>
                            <delivery_date><![CDATA[' . $resources->order->delivery_date . ']]></delivery_date>
                            <valid><![CDATA[' . $resources->order->valid . ']]></valid>
                            <date_add><![CDATA[' . $resources->order->date_add . ']]></date_add>
                            <date_upd><![CDATA[' . $resources->order->date_upddate_upd . ']]></date_upd>
                            <shipping_number notFilterable="true"></shipping_number>
                            <id_shop_group><![CDATA[' . $resources->order->id_shop_group . ']]></id_shop_group>
                            <id_shop><![CDATA[' . $resources->order->id_shop . ']]></id_shop>
                            <secure_key><![CDATA[' . $resources->order->secure_key . ']]></secure_key>
                            <payment><![CDATA[' . $resources->order->payment . ']]></payment>
                            <recyclable><![CDATA[' . $resources->order->recyclable . ']]></recyclable>
                            <gift><![CDATA[' . $resources->order->gift . ']]></gift>
                            <gift_message></gift_message>
                            <mobile_theme><![CDATA[' . $resources->order->mobile_theme . ']]></mobile_theme>
                            <total_discounts><![CDATA[' . $resources->order->total_discounts . ']]></total_discounts>
                            <total_discounts_tax_incl><![CDATA[' . $resources->order->total_discounts_tax_incl . ']]></total_discounts_tax_incl>
                            <total_discounts_tax_excl><![CDATA[' . $resources->order->total_discounts_tax_excl . ']]></total_discounts_tax_excl>
                            <total_paid><![CDATA[' . $resources->order->total_paid . ']]></total_paid>
                            <total_paid_tax_incl><![CDATA[' . $resources->order->total_paid_tax_incl . ']]></total_paid_tax_incl>
                            <total_paid_tax_excl><![CDATA[' . $resources->order->total_paid_tax_excl . ']]></total_paid_tax_excl>
                            <total_paid_real><![CDATA[' . $resources->order->total_paid_real . ']]></total_paid_real>
                            <total_products><![CDATA[' . $resources->order->total_products . ']]></total_products>
                            <total_products_wt><![CDATA[' . $resources->order->total_products_wt . ']]></total_products_wt>
                            <total_shipping><![CDATA[' . $resources->order->total_shipping . ']]></total_shipping>
                            <total_shipping_tax_incl><![CDATA[' . $resources->order->total_shipping_tax_incl . ']]></total_shipping_tax_incl>
                            <total_shipping_tax_excl><![CDATA[' . $resources->order->total_shipping_tax_excl . ']]></total_shipping_tax_excl>
                            <carrier_tax_rate><![CDATA[' . $resources->order->carrier_tax_rate . ']]></carrier_tax_rate>
                            <total_wrapping><![CDATA[' . $resources->order->total_wrapping . ']]></total_wrapping>
                            <total_wrapping_tax_incl><![CDATA[' . $resources->order->total_wrapping_tax_incl . ']]></total_wrapping_tax_incl>
                            <total_wrapping_tax_excl><![CDATA[' . $resources->order->total_wrapping_tax_excl . ']]></total_wrapping_tax_excl>
                            <round_mode><![CDATA[' . $resources->order->round_mode . ']]></round_mode>
                            <round_type><![CDATA[' . $resources->order->round_type . ']]></round_type>
                            <conversion_rate><![CDATA[' . $resources->order->conversion_rate . ']]></conversion_rate>
                            <schedule_date><![CDATA[' . $resources->order->schedule_date . ']]></schedule_date>
                            <distance><![CDATA[' . $resources->order->distance . ']]></distance>
                            <order_end><![CDATA[' . $resources->order->order_end . ']]></order_end>
                            <reference><![CDATA[' . $resources->order->reference . ']]></reference>
                            <remark><![CDATA[' . $resources->order->remark . ' ]]></remark>
                            <Delivery_Time_ID><![CDATA[' . $resources->order->Delivery_Time_ID . ']]></Delivery_Time_ID>
                            <shop_actual_id><![CDATA[' . $resources->order->shop_actual_id . ']]></shop_actual_id>
                            <printed><![CDATA[' . $resources->order->printed . ']]></printed>
                      </order>
                    </prestashop>'
                );
                $opt = [
                    'resource' => 'orders',
                    'id' => (int) $orderData->client_order_id,
                    'putXml' => $xml->asXML(),
                ];
                $res = Prestashop::edit($opt);
                Log::info($res);
            } else if ($orderData->client_id == 2) {
                $data = ['order_id' => $orderData->client_order_id, 'status' => $status, 'tracking_number' => null];
                if ($status == 'Order Accept') {
                    $captain = Captain::find($orderData->captain_id);
                    $data['driver_name'] = $captain->firstname . ' ' . $captain->lastname;
                    $data['mobile_no'] = $captain->phone_number;
                }

                // $res = (new Farawlah())->push($orderData);
                // Log::info($res);
            }
        } catch (\Exception $e) {
            Log::info($e->getMessage());
            return $e->getMessage();
        }
    }

    public function AssignNotification($captain, $order)
    {

        $fcmUrl = 'https://fcm.googleapis.com/fcm/send';
        if ($order->client) {
            $body = 'Order Received From ' . $order->client->user->name;
        }
        if ($order->location) {

            $locales = explode(',', $order->location);
            $lat = $locales[0];
            $lon = $locales[1];

        } else {

            $lat = '';
            $lon = '';
        }

        $metadata = \App\Reminder::getNotificationMetadata(\App\Reminder::ORDER_ASSIGN);
        $data = [
            'priority' => 'High',
            'content_available' => true,
            'body' => __('app/notifications.new_order.body', [], $captain->user->language ?? "en"),
            'title' => __('app/notifications.new_order.title', [], $captain->user->language ?? "en"),
            'shopimage' => '',
            'shopname' => $order->shopname,
            'shopaddress' => $order->client->address,
            'deliverytime' => $order->delivery_time,
            'shoplat' => $lat,
            'orderid' => $order->id,
            'clientorderid' => $order->client_order_id,
            'shoplan' => $lon,
            'shopphone' => $order->client->mobile_number,
            'deliveryboyname' => $order->captain->user->name,
            'locationtoshop' => '',
            'shoptodeliverylocation' => '',
            'deliverylocation' => $order->address,
            'orderamount' => $order->amount,
            "sound" => $metadata['sound'],
            "android_channel_id" => $metadata['android_channel_id'],
            "content_available" => true,
            "mutable_content" => true,
        ];

        FCMSend::dispatch($data, $captain->accessToken->fb_token, null, $captain->firebaseVersion());
        return true;

        $extraNotificationData = ["message" => $data];
        $fcmNotification = [
            'to' => $captain->accessToken->fb_token,
            'notification' => $data,
            'data' => $data
        ];
        $headers = [
            'Authorization: key=AAAAGcTP7Yw:APA91bHliLfeigmnZvy1EG0TQwYove-FpQJiwyAQDv946eIzr28lfKEjstyhFtYNv1W0-Ua-zewkZdal_8bGDWOfC3mQXUVuhR2-Xs7gtbDcJO4TGLCkSh0YeZZYlhODwpChK5Rug-W1',
            'Content-Type: application/json'
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fcmUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fcmNotification));
        $result = curl_exec($ch);
        FacadesLog::info($result);
        curl_close($ch);
        return true;
    }

    public function logs($module, $content, $created_by)
    {
        $log = array('module' => $module, 'content' => $content, 'created_by' => $created_by, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'));
        $result = DB::table('logs')->InsertGetId($log);
        return $result;
    }
}