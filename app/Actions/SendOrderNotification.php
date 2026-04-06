<?php

namespace App\Actions;

use App\Jobs\FCMSend;
use App\Reminder;

final class SendOrderNotification
{
    public static function sendLatestNotification($packageId, $orderCount, $fbToken, $version, $lang = 'en')
    {
        $metadata = Reminder::getNotificationMetadata(Reminder::FORCE_ASSIGN);
        $data = [
            'priority' => 'High',
            'content_available' => true,
            'body' => __('app/notifications.new_order.body', [], $lang),
            'title' => __('app/notifications.new_order.title', [], $lang),
            'reminder_type' => Reminder::FORCE_ASSIGN,
            'id' => $packageId,
            'orders_count' => $orderCount,
            'accept_before' => now()->diffInSeconds(now()->addMinutes(\App\Captain::CAPTAIN_ACCEPTING_TIME_IN_MINUTES), false),
            'shopimage' => '',
            'sound' => $metadata['sound'],
            'android_channel_id' => $metadata['android_channel_id'],
            'mutable_content' => true,
        ];

        FCMSend::dispatch($data, $fbToken, null, $version);
        return true;
    }
    public static function sendOldNotification($order,$fbToken, $version, $lang = 'en')
    {
        if ($order->location) {
            $locales = explode(',', $order->location);
            $lat = $locales[0];
            $lon = $locales[1];
        } else {
            $lat = '';
            $lon = '';
        }

        $metadata = Reminder::getNotificationMetadata(Reminder::ORDER_ASSIGN);
        $data = [
            'priority' => 'High',
            'content_available' => true,
            'body' => __('app/notifications.new_order.body', [], $lang),
            'title' => __('app/notifications.new_order.title', [], $lang),
            'reminder_type' => Reminder::ORDER_ASSIGN,
            'accept_before' => now()->diffInSeconds(now()->addMinutes(\App\Captain::CAPTAIN_ACCEPTING_TIME_IN_MINUTES), false),
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
            'sound' => $metadata['sound'],
            'android_channel_id' => $metadata['android_channel_id'],
            'mutable_content' => true,
        ];

        FCMSend::dispatch($data, $fbToken, null, $version);
        return true;
    }
}
