<?php

namespace App\Services\Mobile;

use App\Order;
use App\OrderPendingReason;
use App\OrderStatus;
use App\Ticket;
use App\TicketReason;
use ReflectionClass;

final class ProcessOrderService
{
    public static function processOrdersListForDashboard($orders, $language, $themeMode): array
    {
        $currentOrders = [];
        $groupedOrders = [];

        $language = in_array($language, ['en', 'ar']) ? $language : 'en';
        $isArabic = $language === 'ar';

        app()->setLocale($language);
        $secondaryColors = self::getButtonColors($themeMode, 2);

        // Primary status-based actions
       $statusActions = [
            OrderStatus::ACCEPT => [['type' => 'CHANGE_STATUS', 'id' => 'START_RIDE', 'status_id' => 4, 'label' => __('app/dashboard-orders-list.Start_ride')]],
            OrderStatus::START_RIDE => [['type' => 'CHANGE_STATUS', 'id' => 'REACHED_SHOP', 'status_id' => 5, 'label' => __('app/dashboard-orders-list.reached_shop'), 'location_required' => true]],
            OrderStatus::REACHED_SHOP => [['type' => 'CHANGE_STATUS', 'id' => 'PICKED', 'status_id' => 6, 'label' => __('app/dashboard-orders-list.pick_up'), 'location_required' => true]],
            OrderStatus::PICKED => [['type' => 'CHANGE_STATUS', 'id' => 'SHIPPED', 'status_id' => 8, 'label' => __('app/dashboard-orders-list.Start_delivery'), 'confirmation_required' => true, 'pop_message' => __('app/dashboard-orders-list.pop_message_pick_up'), 'location_required' => true]],
            OrderStatus::SHIPPED => [['type' => 'CHANGE_STATUS', 'id' => 'REACHED_DESTINATION', 'status_id' => 9, 'label' => __('app/dashboard-orders-list.reached_destination')]],
            OrderStatus::REACHED_DESTINATION => [['type' => 'CHANGE_STATUS', 'id' => 'REROUTED', 'status_id' => 22, 'label' => __('app/dashboard-orders-list.re_route'), 'location_required' => true, 'background_color' => $secondaryColors['background_color'], 'text_color' => $secondaryColors['text_color'], 'confirmation_required' => true, 'confirmation_title' => __('app/dashboard-orders-list.re_route'), 'confirmation_message' => __('app/dashboard-orders-list.re_route_confirmation')], ['type' => 'CHANGE_STATUS', 'id' => 'DELIVERED', 'status_id' => 10, 'label' => __('app/dashboard-orders-list.make_delivery'), 'location_required' => true]],
            OrderStatus::TICKET_RAISED => [['type' => 'CHANGE_STATUS', 'id' => 'PICKED', 'status_id' => 6, 'label' => __('app/dashboard-orders-list.pick_up')]],
            OrderStatus::PENDING => [['type' => 'CHANGE_STATUS', 'id' => 'PICKED', 'status_id' => 6, 'background_color' => $secondaryColors['background_color'], 'text_color' => $secondaryColors['text_color'], 'label' => __('app/dashboard-orders-list.shipment_again')], ['type' => 'CHANGE_STATUS', 'id' => 'RETURN_TO_CLIENT', 'status_id' => 15, 'label' => __('app/dashboard-orders-list.return'), 'location_required' => true]],
            OrderStatus::RETURN_TO_CLIENT => [['type' => 'CHANGE_STATUS', 'id' => 'REROUTED', 'status_id' => 22, 'label' => __('app/dashboard-orders-list.re_route'), 'location_required' => true]],
            OrderStatus::REROUTED => [['type' => 'CHANGE_STATUS', 'id' => 'REACHED_DESTINATION', 'status_id' => 9, 'label' => __('app/dashboard-orders-list.reached_destination'), 'location_required' => true]],
        ];
        $primaryColor = self::getButtonColors($themeMode, 1);

        $defaultAction = [
            'type' => null,
            'id' => null,
            'label' => null,
            'background_color' => $primaryColor['background_color'],
            'text_color' => $primaryColor['text_color'],
            'confirmation_required' => false,
            'location_required' => true
        ];

        $other_reason = collect([
            'id' => 0,
            'reason' => $isArabic ? 'أخرى' : 'Other',
            'add_reason_text' => true,
        ]);

        $pending_reasons = OrderPendingReason::select('id', $isArabic ? 'reason_ar as reason' : 'reason')
            ->get()
            ->reject(function ($item) {
                $otherTexts = ['other', 'others', 'أخرى'];
                return in_array(mb_strtolower(trim($item->reason)), $otherTexts, true);
            });

        $ticket_reasons = TicketReason::active()
            ->select('id', $isArabic ? 'reason_ar as reason' : 'reason')
            ->get();

        $pending_reasons = $pending_reasons->push($other_reason);
        $ticket_reasons = $ticket_reasons->push($other_reason);

        $tertiaryColors = self::getButtonColors($themeMode, 3);

        $moveToPending = [
            'type' => 'CREATE_TICKET',
            'label' => __('app/dashboard-orders-list.move_pending'),
            'background_color' => $tertiaryColors['background_color'],
            'text_color' => $tertiaryColors['text_color'],
            'confirmation_title' => __('app/dashboard-orders-list.are_you_sure'),
            'confirmation_required' => true,
            'confirmation_message' => __('app/dashboard-orders-list.are_you_sure_pending'),
            'reasons' => $pending_reasons,
        ];

        $extraActions = [
            OrderStatus::REACHED_SHOP => [
                'type' => 'CREATE_TICKET',
                'label' => __('app/dashboard-orders-list.create_ticket'),
                'background_color' => $tertiaryColors['background_color'],
                'text_color' => $tertiaryColors['text_color'],  
                'confirmation_title' => __('app/dashboard-orders-list.are_you_sure'),
                'confirmation_required' => true,
                'confirmation_message' => __('app/dashboard-orders-list.are_you_sure_want_start_ticket'),
                'reasons' => $ticket_reasons,
            ],
            OrderStatus::REACHED_DESTINATION => $moveToPending,
            OrderStatus::SHIPPED => $moveToPending,
            OrderStatus::PENDING => [
                'type' => 'CHAT_WITH_SUPPORT',
                'label' => __('app/dashboard-orders-list.support_chat'),
                'ticket_id' => '',
                'background_color' => $tertiaryColors['background_color'],
                'text_color' => $tertiaryColors['text_color'],
                'confirmation_required' => false,
            ],
            OrderStatus::TICKET_RAISED => [
                'type' => 'CHAT_WITH_SUPPORT',
                'label' => __('app/dashboard-orders-list.support_chat'),
                'ticket_id' => '',
                'background_color' => $tertiaryColors['background_color'],
                'text_color' => $tertiaryColors['text_color'],
                'confirmation_required' => false,
            ],
        ];

        $order_count = [
            'normal_orders' => 0,
            'ticket_orders' => 0,
            'pending_orders' => 0,
        ];

        foreach ($orders as $order) {
            $shopLocation = $order->shop?->location;

            if (!empty($shopLocation) && str_contains($shopLocation, ',')) {
                [$lat, $lon] = array_pad(explode(',', $shopLocation), 2, 0);
            } else {
                $lat = 0;
                $lon = 0;
            }


            $clientId = $order->client_id ?? 'unknown';
            $shopId = $order->shop->id ?? 'unknown';

            [$start_at, $ended_at] = $order->remainingTime();

            $tickets = Ticket::with('reason')->where('order_id', $order->id)->whereNull('closed_at')->latest('id')->get();
            $latestType = $tickets->first()?->type;

            $category = match ([$latestType, $order->status_id]) {
                [1, 21] => 'TICKET',
                [2, 18] => 'PENDING',
                default => 'NORMAL',
            };

            match ([$latestType, $order->status_id]) {
                [1, 21] => $order_count['ticket_orders']++,
                [2, 18] => $order_count['pending_orders']++,
                default => $order_count['normal_orders']++,
            };

            $clientName = $order->client->user->name ?? 'N/A';
            $status = $order->status_id;
            if (in_array($status, [18, 21])) {
                $ticketType = $status == 21 ? 1 : 2;
                $ticket = Ticket::where('order_id', $order->id)->where('type', $ticketType)->whereNull('closed_at')->first();
                if (isset($extraActions[$status])) {
                    $extraActions[$status]['ticket_id'] = $ticket?->id ?? null;
                }
            }
            $finalActions = [];
            if (isset($extraActions[$status])) {
                $finalActions[] = $extraActions[$status];
            }

            $latitude = $order->latestAddress?->latitude;
            $longitude = $order->latestAddress?->longitude;

            $location = [
                'isChangeable' => $order->reroutable(),
            ];

            if (!is_null($latitude) && !is_null($longitude)) {
                $location['coordinates'] = [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ];
            }
            $diffInSeconds = now()->diffInSeconds($ended_at, false);

            $mappedOrder = [
                'order_id' => $order->id,
                'order_code' => $order->client_order_id,

                'title' => __('app/order-status.' . $order->status),

                'order_status' => [
                    'status' => self::getOrderStatusName($status),
                    'label' => ucfirst(strtolower(self::getOrderStatusName($status))),
                ],

                'time_status' => [
                    'label' => now()->diffInSeconds($ended_at, false) < 0 ? 'Delayed' : 'On Time',
                    'time_in_seconds' => abs(now()->diffInSeconds($ended_at, false)),
                    'is_count_down' => $diffInSeconds >= 0,
                ],

                'order_attributes' => [['label' => __('app/dashboard-orders-list.delivery_fee'), 'value' => 'SAR ' . number_format((float) $order->delivery_charge, 2)], ['label' => __('app/dashboard-orders-list.order_amount'), 'value' => 'SAR ' . number_format((float) $order->amount, 2)], ['label' => __('app/dashboard-orders-list.payment_type'), 'value' => $order->delivery_payment_mode == Order::ORDER_PAYMENT_MODE_AUTO ? __('app/dashboard-orders-list.by_cash') : __($order->delivery_payment_mode)]],

                'dropOff' => [
                    'name' => $order->customer_name ?? '',
                    'mobile_number' => '+966' . $order->customer_number,
                    // 'location' => $location,
                    'contact' => [
                        'show_whatsapp' => true,
                        'show_call' => true,
                    ],
                ],

                'summary' => [
                    'title' => __('app/dashboard-orders-list.order_details'),
                    'attributes' => [['label' => __('app/dashboard-orders-list.order_amount'), 'value' => 'SAR ' . number_format((float) $order->amount, 2)], ['label' => __('app/dashboard-orders-list.payment_type'), 'value' => $order->delivery_payment_mode == Order::ORDER_PAYMENT_MODE_AUTO ? __('app/dashboard-orders-list.by_cash') : __($order->delivery_payment_mode)]],
                ],
            ];

            // REMOVE only for ACCEPT, START_RIDE, REACHED_SHOP
            if (in_array($order->status_id, [OrderStatus::ACCEPT, OrderStatus::START_RIDE, OrderStatus::REACHED_SHOP])) {
                unset($mappedOrder['dropOff'], $mappedOrder['summary']);
            }

            // ADD location only for PICKED, SHIPPED, REACHED_DESTINATION
            if (in_array($order->status_id, [OrderStatus::PICKED, OrderStatus::SHIPPED, OrderStatus::REACHED_DESTINATION])) {
                if (!isset($mappedOrder['dropOff'])) {
                    $mappedOrder['dropOff'] = [];
                }
                unset($mappedOrder['order_attributes']);
                if (!empty($order->location)) {
                    $mappedOrder['dropOff']['location'] = [
                        'isChangeable' => true,
                        'coordinates' => [
                            'latitude' => (float) explode(',', $order->location)[0],
                            'longitude' => (float) explode(',', $order->location)[1],
                        ],
                    ];
                }
            }

            if (!empty($finalActions)) {
                $mappedOrder['actions'] = $finalActions;
            }

            // Primary workflow actions (status → button)
            $primaryActions = [];
            if (isset($statusActions[$status])) {
                foreach ($statusActions[$status] as $act) {
                    if ($status == OrderStatus::REACHED_DESTINATION && 0 != $order->amount && in_array($order->delivery_payment_mode, [ORDER::ORDER_PAYMENT_MODE_AUTO, ORDER::ORDER_PAYMENT_MODE_SWIPING_MACHINE])) {
                        $mappedOrder['summary'];
                        if ($act['id'] == 'DELIVERED' && $order->delivery_payment_mode != Order::ORDER_PAYMENT_MODE_SWIPING_MACHINE) {
                            $act['otp_required'] = !$order->otpVerificationNeeded() ? false : true;
                            $act['fetch_payment_details'] = ['amount' => round((float) $order->amount, 2), 'currency' => 'SAR'];
                        } elseif ($act['id'] == 'DELIVERED' && $order->delivery_payment_mode === ORDER::ORDER_PAYMENT_MODE_SWIPING_MACHINE) {
                            $act['confirmation_required'] = true;
                            $act['otp_required'] = !$order->otpVerificationNeeded() ? false : true;
                            $act['confirmation_title'] = __('app/dashboard-orders-list.confirm');
                            $act['confirmation_message'] = __('app/dashboard-orders-list.collect_bill_message');
                        }
                    } elseif ($status == OrderStatus::REACHED_DESTINATION) {
                        $mappedOrder['summary'];
                        if ($act['id'] == 'DELIVERED') {
                            // $act['otp_required'] = !$order->otpVerificationNeeded() ? false : true;
                            $otpRequired = $order->otpVerificationNeeded();
                            //$act['otp_required'] = $otpRequired;
                            if (!$otpRequired) {
                                $act['otp_required'] = false;
                                $act['confirmation_required'] = true;
                                $act['confirmation_title'] = __('app/dashboard-orders-list.are_you_sure');
                                $act['confirmation_message'] = __('app/dashboard-orders-list.are_you_sure_make_delivery');
                            } else {
                                $act['otp_required'] = true;
                            }
                            $act['location_required'] = true;
                        }
                        if ($act['id'] == 'REROUTED') {
                            $act['otp_required'] = false;
                        }
                    } elseif ($status == OrderStatus::REACHED_SHOP || $status == OrderStatus::TICKET_RAISED) {
                        $act['confirmation_required'] = true;
                        $act['confirmation_title'] = __('app/dashboard-orders-list.confirm_pickup');
                        if (0 != $order->amount && $order->delivery_payment_mode == ORDER::ORDER_PAYMENT_MODE_AUTO) {
                            //$act['confirmation_message'] = "Please confirm that you are picking up the Order ID: ".$order->client_order_id." A payment of SAR ".$order->amount." is required at the store";
                            $act['confirmation_message'] = __('app/dashboard-orders-list.pickup_confirmation', ['order_id' => $order->client_order_id, 'amount' => $order->amount]);
                        } else {
                            $act['confirmation_message'] = __('app/dashboard-orders-list.pickup_confirmation', ['order_id' => $order->client_order_id]);
                        }
                        $act['proof_of_pickup'] = $order->client?->proof_of_pickup ?? false; 
                    } elseif ($status == OrderStatus::PICKED) {
                        $act['confirmation_required'] = true;
                        $act['confirmation_title'] = __('app/dashboard-orders-list.are_you_sure');
                        $act['confirmation_message'] = __('app/dashboard-orders-list.are_you_sure_start_delivery');
                    }

                    $primaryActions[] = array_merge($defaultAction, $act);
                }
            }

            // $groupKey = $clientId . '_' . $category;

            $clientId = $order->client_id ?? 'unknown';
            $shopId = $order->shop->id ?? 'unknown';

            if ($order->status_id == OrderStatus::ACCEPT || $order->status_id == OrderStatus::START_RIDE ) {
                $groupKey = $clientId . '_' . $shopId . '_' . $category . '_' . $order->status_id;
            } else {
                $groupKey = $order->id . '_' . $category;
            }

            if (!isset($currentOrders[$groupKey])) {
                // first order for this client+category → create group
                $currentOrders[$groupKey] = [
                    'orders' => [],
                    'order_category' => $category,
                    'pickup' => [
                        'name' => $order->shop->app_name ?: ($order->shop->brand?->name_en ?: $order->shop->name),
                        'logo' =>  $order->shop?->brand?->logo ?? $order->client?->company_logo_path ?? null,
                        'coordinates' => [
                            'latitude' => (float) $lat,
                            'longitude' => (float) $lon,
                        ],
                    ],
                    'actions' => $primaryActions,
                ];
            }
            if (!in_array($order->status_id, OrderStatus::NEED_LOCATION_STATUS_CAPTAINS)) {
                unset($currentOrders[$groupKey]['pickup']['coordinates']);
            }
            // append order into the existing group
            $currentOrders[$groupKey]['orders'][] = $mappedOrder;
        }
        // reindex array (remove string keys)
        $currentOrders = array_values($currentOrders);

        return [
            'current_orders' => $currentOrders,
            'order_count' => $order_count,
        ];
    }

    private static function getButtonColors(string $theme = 'light', int $pattern = 1): array
    {
        $theme = strtolower($theme ?? 'light');

        $themes = [
            'light' => [
                1 => ['background_color' => '#ED7121', 'text_color' => '#FFFFFF'],
                2 => ['background_color' => '#18415F', 'text_color' => '#FFFFFF'],
                3 => ['background_color' => '#576E7C', 'text_color' => '#FFFFFF'],
            ],

            'dark' => [
                1 => ['background_color' => '#ED7121', 'text_color' => '#FFFFFF'],
                2 => ['background_color' => '#18415F', 'text_color' => '#FFFFFF'],
                3 => ['background_color' => '#586366', 'text_color' => '#FFFFFF'],
            ],
        ];
        return $themes[$theme][$pattern] ?? $themes['light'][1];
    }

    private static function getOrderStatusName(int $statusId): string
    {
        static $statusMap = null;

        if ($statusMap === null) {
            $reflection = new ReflectionClass(OrderStatus::class);
            $constants = $reflection->getConstants();

            // Filter only scalar values (integers/strings)
            $filteredConstants = array_filter($constants, function ($value) {
                return is_int($value) || is_string($value);
            });

            // Flip filtered array (value => name)
            $statusMap = array_flip($filteredConstants);
        }

        return $statusMap[$statusId] ?? 'UNKNOWN';
    }
}
