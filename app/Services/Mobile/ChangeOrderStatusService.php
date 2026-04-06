<?php

namespace App\Services\Mobile;

use App\Captain;
use Illuminate\Support\Facades\Notification;
use App\Events\OrderAddressChanged;
use App\Events\OrderRejected;
use App\Events\OrderStatusChanged;
use App\Events\TicketClosed;
use App\Notifications\DeliveryShipped;
use App\Order;
use App\OrderStatus;
use App\Package;
use App\PackageDeliveryRequest;
use App\Services\Position;
use App\Services\WalletService;
use App\Ticket;
use App\Vat;
use Exception;
use Facades\App\Services\OrderStatusLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

final class ChangeOrderStatusService
{
    public static function changeBulkStatus(array $orderIds, OrderStatus $status, Captain $captain, Request $request)
    {
        $order_num = 1;
        $captainData = $captain;
        $captain_id = $captain->id;
        $status_id = $request->data['current_status'];
        $delivery_otp = $request->data['otp'] ?? null;

        $reason = $request->data['reason'] ?? null;

        foreach ($orderIds as $key => $order_id) {
            $order = Order::with('progress', 'shop')->find($order_id);

            if ($captain_id != $order->captain_id) {
                throw new Exception("You are no longer in possession of this order. Please refresh");
            }

            // if ($status->id == OrderStatus::ACCEPT && !in_array($order->status_id, [OrderStatus::WAITING_FOR_ACCEPTING])) {
            //     throw new Exception("You are no longer in possession of this order. Your request is expired. Please refresh");
            // }

            if ($status->id == OrderStatus::ACCEPT) {
                Package::whereHas('orders', function ($query) use ($captain_id, $order_id) {
                    $query->where('captain_id', $captain_id);
                    $query->where('order_id', $order_id);
                })->update(['captain_accepted_at' => now()]);
            }

            if ($status->id == OrderStatus::CAPTAIN_ORDER_REJECTED) {
                PackageDeliveryRequest::query()
                    ->where('captain_id', '=', $captain_id)
                    ->whereHas('package.orders', function ($query) use ($order_id) {
                        $query->where('order_id', $order_id);
                    })
                    ->update(['declined_at' => now()]);
            }

            if (!in_array($status->id, [OrderStatus::ACCEPT, OrderStatus::CAPTAIN_ORDER_REJECTED]) && $order->status_id == OrderStatus::WAITING_FOR_ACCEPTING) {
                throw new Exception('Please Accept this order before moving forward');
            }

            if (in_array($order->status_id, [OrderStatus::DELIVERED])) {
                throw new Exception('This order is already delivered you cant change the order status anymore');
            }

            if (in_array($order->status_id, [OrderStatus::CANCEL, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::CLIENT_RETURN_ACCEPTED])) {
                throw new Exception('This order is already canceled you cant change the order status anymore');
            }

            if ($status_id == OrderStatus::DELIVERED && ($order_otp = $order->otpVerificationNeeded())) {
                if (!$delivery_otp) {
                    throw new Exception('Delivery otp is required to complete the order');
                }
                $validate_otp = Hash::check($delivery_otp, $order_otp->otp);

                if (!$validate_otp) {
                    $meg = 'Invalid OTP';
                    throw new Exception($meg);
                }
            }

            $module = 'Order';

            if ($order->shop && $order->shop->verify_captain_reached_shop) {
                if (in_array($status_id, [OrderStatus::REACHED_SHOP]) && $order->shop->location) {
                    [$shop_lat, $shop_long] = explode(',', $order->shop->location);
                    if (isset($request->get('data')['lat']) && isset($request->get('data')['long'])) {
                        $long = $request->get('data')['long'];
                        $lat = $request->get('data')['lat'];
                        $distance = (new Position($long, $lat))->airDistance(new Position($shop_long, $shop_lat))->distance();
                        if ($distance > $order->shop->captain_reached_shop_distance) {
                            throw new Exception(__('app/orders.reached_shop_limit', ['limit' => $order->shop->captain_reached_shop_distance]));
                        }
                    } else {
                        throw new Exception('Captain location required');
                    }
                }
            }

            if ($order->shop && $order->shop->verify_captain_reached_pickup_point && $order_num == 1) {
                if (in_array($status_id, [OrderStatus::SHIPPED]) && $order->shop->location) {
                    [$shop_lat, $shop_long] = explode(',', $order->shop->location);
                    if (isset($request->get('data')['lat']) && isset($request->get('data')['long'])) {
                        $long = $request->get('data')['long'];
                        $lat = $request->get('data')['lat'];
                        $distance = (new Position($long, $lat))->airDistance(new Position($shop_long, $shop_lat))->distance();

                        if ($distance > $order->shop->reached_pickup_point_distance) {
                            throw new Exception(__('app/orders.reached_pickup_point_limit', ['limit' => $order->shop->reached_pickup_point_distance]));
                        }
                    } else {
                        throw new Exception('Captain location required');
                    }
                }
            }
            $order_num++;

            if ($order->shop && $order->shop->verify_captain_reached_destination) {
                if (in_array($status_id, [OrderStatus::REACHED_DESTINATION, OrderStatus::DELIVERED]) && $order->location && !$order->isRerouted()) {
                    // Parse order location safely
                    [$order_lat, $order_long] = array_pad(explode(',', (string) $order->location), 2, null);

                    if (is_numeric($order_lat) && is_numeric($order_long)) {

                        if (isset($request->get('data')['lat']) && isset($request->get('data')['long'])) {
                            $long = $request->get('data')['long'];
                            $lat = $request->get('data')['lat'];
                            $distance = (new Position($long, $lat))->airDistance(new Position($order_long, $order_lat))->distance();
                            if ($distance > $order->shop->reached_destination_distance) {
                                throw new Exception(__('app/orders.reach_destination_limit', ['limit' => $order->shop->reached_destination_distance]));
                            }
                        } else {
                            throw new Exception('Captain location required');
                        }
                    }
                }
            }

            if ($status_id == OrderStatus::REACHED_DESTINATION && !$order->location && !$order->isRerouted()) {

                if (isset($request->get('data')['lat']) && isset($request->get('data')['long'])) {
                    $lat = $request->get('data')['lat'] ?? '';
                    $long = $request->get('data')['long'] ?? '';

                    $meta = $order->meta ?? [];
                    $meta['is_location_missing_initially'] = true;

                    $order->update([
                        'location' => $lat . ',' . $long,
                        'meta' => $meta,
                    ]);

                    OrderAddressChanged::dispatch($order);
                }
            }

            if ($status_id == OrderStatus::REACHED_DESTINATION && $order->isRerouted()) {
                if (isset($request->get('data')['lat']) && isset($request->get('data')['long'])) {
                    $lat = $request->get('data')['lat'];
                    $long = $request->get('data')['long'];
                    if (!$order->location) {
                        $order->update([
                            'location' => $lat . ',' . $long,
                        ]);
                    } else {
                        $order->addresses()->create([
                            'latitude' => $request->get('data')['lat'],
                            'longitude' => $request->get('data')['long'],
                        ]);
                    }
                    OrderAddressChanged::dispatch($order);
                } else {
                    throw new Exception('Captain location required');
                }
            }

            if (!in_array($status_id, [OrderStatus::TICKET_RAISED, OrderStatus::PENDING]) && ($tickets = $order->openTickets)) {
                foreach ($tickets as $key => $ticket) {
                    if ($ticket->type != Ticket::TYPE_CLIENT || ($ticket->type == Ticket::TYPE_CLIENT && $status_id == OrderStatus::DELIVERED)) {
                        $ticket->update([
                            'closed_at' => now(),
                        ]);
                        TicketClosed::dispatch($ticket);
                    }
                }
            }

            $content = 'Order No ' . $order->client_order_id . ' Status changed from ' . $order->progress->name . ' to ' . $status->name;
            OrderStatusLog::logs($module, $content, $captainData->user_id);
            if ($status_id == OrderStatus::CANCEL) {
                $order->update(['status_id' => $status_id, 'created_by' => $captainData->user_id, 'captain_id' => null]);
            } else {
                if ($status_id == OrderStatus::CAPTAIN_ORDER_REJECTED) {
                    $order->update([
                        'status_id' => OrderStatus::NEW_ORDER,
                        'captain_id' => null,
                    ]);
                } else {
                    if ($status_id == OrderStatus::DELIVERED && $order->status_id == OrderStatus::REACHED_DESTINATION) {
                        if (in_array($order->delivery_payment_mode, [ORDER::ORDER_PAYMENT_MODE_AUTO, ORDER::ORDER_PAYMENT_MODE_SWIPING_MACHINE]) && 0 != $order->amount) {
                            $walletService = new WalletService();
                            $request->merge(['captain_id' => $captain_id]);
                            $addWallet = $walletService->addWallet($request, $order_id);
                            if ($addWallet['status'] == false) {
                                throw new Exception($addWallet['message']);
                            } else {
                                $vat = Vat::where('status', 'active')->orderBy('id', 'desc')->first();
                                $order->update(['status_id' => OrderStatus::DELIVERED, 'vat_rate' => isset($vat) ? $vat->rate : 0]);
                            }
                        } else {
                            $order->update(['status_id' => OrderStatus::DELIVERED, 'vat_rate' => 0]);
                        }
                    } else {
                        $order->update([
                            'status_id' => $status_id,
                        ]);
                    }
                }
            }

            if ($status_id == OrderStatus::SHIPPED && (($order->delivery_payment_mode == ORDER::DELIVERY_PAYMENT_TYPE_PRE_PAID && $order->client->send_otp_for_prepaid_orders) || (in_array($order->delivery_payment_mode, [ORDER::ORDER_PAYMENT_MODE_AUTO, ORDER::ORDER_PAYMENT_MODE_SWIPING_MACHINE]) && $order->client->send_otp_for_cod_orders))) {
                Notification::route('sms_api', $order->customer_number)->notify(new DeliveryShipped($order));
            }

            OrderStatusLog::log($status_id, $captain_id, $order_id, $reason);
            if ($status_id == OrderStatus::CAPTAIN_ORDER_REJECTED) {
                OrderRejected::dispatch($order);
                OrderStatusLog::log(OrderStatus::NEW_ORDER, null, $order_id, null, null, null, config('app.system_user'));
            }
            //$msg = "Order Status Updated Successfully";

            if ($status_id == OrderStatus::DELIVERED) {
                $msg = 'Order Delivered Successfully';
            } else {
                $msg = null;
            }

            $orderModels[] = $order;
        }
        foreach ($orderModels as $order) {
            Log::info('v2.change_order_status.dispatching_order_status_changed', [
                'order_id' => $order->id,
                'status_id' => $order->status_id,
                'status_name' => optional($order->progress)->name,
                'captain_id' => $captain_id,
                'source' => 'api_v2',
                'dispatched_at' => now()->toDateTimeString(),
            ]);

            OrderStatusChanged::dispatch($order);

            Log::info('v2.change_order_status.dispatched_order_status_changed', [
                'order_id' => $order->id,
                'status_id' => $order->status_id,
                'captain_id' => $captain_id,
                'source' => 'api_v2',
                'completed_at' => now()->toDateTimeString(),
            ]);
        }

        return true;
    }
}
