<?php

namespace App\Services;

use App\Order;
use App\OrderStatus;
use App\CancellationReason;
use App\OrderPendingReason;
use App\Ticket;
use App\ThirdPartyOrderStatusPushLog;
use Carbon\Carbon;

class OrderService
{
    public function fetchOrderDetails($orderId)
    {
        $order = Order::select('orders.*')
            ->belongsToMe()
            ->with([
                'captain', 
                'client.user', 
                'items',
                'logsExecpt.progress', 
                'logsExecpt.createdBy', 
                'addresses',
                'shop:id,name',
                'notes.user',
                'payment'
            ])
            ->findOrFail($orderId);

        $order_statuses = OrderStatus::orderBy('priority')
            ->orderBy('id')
            ->get();

        $order_cancellation_reasons = CancellationReason::active()
            ->get();

        $order_pending_reasons = OrderPendingReason::active()
            ->get();

        $ticket = Ticket::where('order_id', $order->id)
            ->where('type', Ticket::TYPE_TICKET)
            ->with('messages', 'captain')
            ->latest()
            ->first();

        $pending_ticket = Ticket::where('order_id', $order->id)
            ->where('type', Ticket::TYPE_PENDING)
            ->with('messages', 'captain')
            ->latest()
            ->first();

        $client_ticket = Ticket::where('order_id', $order->id)
            ->where('type', Ticket::TYPE_CLIENT)
            ->with('messages.sender.client')
            ->latest()
            ->first();

        $third_party_push_logs = ThirdPartyOrderStatusPushLog::where('order_id', $order->id)
            ->get();

        $formattedLogs = $this->formatOrderLogs($order);

        return [
            'order' => [
                'basic_info' => [
                    'id' => $order->id,
                    'code' => $order->code,
                    'client_order_id' => $order->client_order_id,
                    'created_at' => $order->created_at->format('Y-m-d H:i:s'),
                    'status' => $order->progress ? $order->progress->name : 'N/A',
                    'client_name' => $order->client->user->name ?? 'N/A',
                    'shop_name' => $order->shop->name ?? 'N/A',
                    'order_type' => $order->delivery_type,
                    'status_class' => $order->progress ? $order->progress->status_class : '',
                    'status_id' => $order->status_id,
                    'is_finished' => $order->finished(),
                    'is_actionable' => $order->actionable(),
                    'is_client_cancelable' => $order->clientCancelable(),
                    'is_returned_to_client' => $order->returnedToClient(),
                    'is_reassignable' => $order->reAssignable(),
                ],
                'shipping_info' => [
                    'client_name' => $order->client->user->name ?? 'N/A',
                    'shop_name' => $order->shop->name ?? $order->shopname,
                    'created_date' => $order->created_at->format('d-m-Y h:i:A'),
                    'delivery_type' => $order->delivery_type,
                    'delivery_date' => $order->delivery_type == 'Scheduled' 
                        ? $order->delivery_date->format('d-m-Y') . ' ' . ($order->timeSlot ? $order->timeSlot->name : '') 
                        : null
                ],
                'billing_info' => [
                    'customer_name' => $order->customer_name,
                    'customer_number' => $order->customer_number,
                    'customer_email' => $order->email,
                    'address' => $order->address ?: $order->location,
                    'additional_addresses' => $order->addresses->map(function($address) {
                        return [
                            'address' => $address->address ?: "{$address->latitude}, {$address->longitude}"
                        ];
                    })
                ],
                'delivery_info' => [
                    'payment_mode' => $order->delivery_payment_mode == 'Auto' 
                        ? (isset($order->payment) ? $order->payment->payment_mode : 'Not Done')
                        : $order->delivery_payment_mode,
                    'captain_name' => isset($order->captain_id) ? $order->captain->user->name : 'Not Assigned',
                    'captain_mobile' => isset($order->captain_id) ? $order->captain->phone_number : '',
                    'amount' => $order->amount,
                    'delivery_charge' => $order->delivery_charge
                ],
                'items' => $order->items->map(function($item) {
                    return [
                        'product_name' => $item->product_name,
                        'quantity' => $item->quantity,
                        'amount' => $item->amount,
                        'total' => $item->total,
                        'customizations' => $item->customizations->map(function($customization) {
                            return [
                                'quantity' => $customization->quantity,
                                'customization' => $customization->customization
                            ];
                        })
                    ];
                }),
                'notes' => $this->formatOrderNotes($order)
            ],
            'logs' => $formattedLogs,
            // 'available_statuses' => $order_statuses->map(function($status) {
            //     return [
            //         'id' => $status->id,
            //         'name' => $status->name,
            //         'priority' => $status->priority
            //     ];
            // }),
            // 'cancellation_reasons' => $order_cancellation_reasons->map(function($reason) {
            //     return [
            //         'id' => $reason->id,
            //         'reason' => $reason->reason,
            //         'is_caused_by_4u' => $reason->is_caused_by_4u
            //     ];
            // }),
            // 'pending_reasons' => $order_pending_reasons->map(function($reason) {
            //     return [
            //         'id' => $reason->id,
            //         'reason' => $reason->reason
            //     ];
            // }),
            'tickets' => [
                'regular' => $ticket ? $this->formatTicket($ticket) : null,
                'pending' => $pending_ticket ? $this->formatTicket($pending_ticket) : null,
                'client' => $client_ticket ? $this->formatTicket($client_ticket) : null
            ],
            'third_party_logs' => $third_party_push_logs->map(function($log) {
                return [
                    'payload'   => $log->payload,
                    'status' => $log->status,
                    'response' => $log->response,
                    'created_at' => $log->created_at->format('Y-m-d H:i:s')
                ];
            })
        ];
    }

    private function formatOrderLogs($order)
    {
        $logs = [];
        $previous_log = $order->logsExecpt->first();
        $new_order = $order->logsExecpt->first();
        $key_time = null;
        $key_time_previous = null;
        
        // Group repeated logs by status
        $status_repeated_logs = [];
        $previous_status = null;
        
        foreach ($order->logsExecpt as $key => $log) {
            // Handle status change detection
            if ($key && $log->status_id != $order->logsExecpt[$key - 1]->status_id) {
                $previous_log = $order->logsExecpt[$key - 1];
            }
            
            // Track repeated logs
            if (isset($order->logsExecpt[$key + 1]) && $log->status_id == $order->logsExecpt[$key + 1]->status_id) {
                if (!isset($status_repeated_logs[$log->status_id])) {
                    $status_repeated_logs[$log->status_id] = [];
                }
                $status_repeated_logs[$log->status_id][] = $this->formatSingleLog($log);
                continue;
            }
            
            // Key time tracking for important status changes
            if (!$key_time_previous && in_array($log->status_id, [
                OrderStatus::NEW_ORDER, 
                OrderStatus::START_RIDE, 
                OrderStatus::REACHED_SHOP, 
                OrderStatus::PICKED
            ])) {
                $key_time_previous = $log;
            }
            
            if ($key_time_previous && in_array($log->status_id, [
                OrderStatus::ACCEPT, 
                OrderStatus::REACHED_SHOP, 
                OrderStatus::PICKED, 
                OrderStatus::DELIVERED
            ])) {
                $key_time = $log->created_at->diffInSeconds($key_time_previous->created_at);
                if (in_array($log->status_id, [OrderStatus::REACHED_SHOP, OrderStatus::PICKED])) {
                    $key_time_previous = $log;
                } else {
                    $key_time_previous = null;
                }
            }
            
            // Calculate km between if applicable
            $km_between = null;
            if ($log->status_id == OrderStatus::REACHED_SHOP && $order->captain_to_shop_km) {
                $km_between = $order->captain_to_shop_km;
            } else if ($log->status_id == OrderStatus::DELIVERED && $order->shop_to_delivery_km) {
                $km_between = $order->shop_to_delivery_km;
            }
            
            // Add formatted log to results
            $logs[] = [
                'id' => $log->id,
                'status' => $log->progress ? $log->progress->name : 'N/A',
                'status_id' => $log->status_id,
                'created_by' => isset($log->createdBy) ? $log->createdBy->name : 'System',
                'date' => $log->created_at->format('Y-m-d'),
                'time' => $log->created_at->format('h:i:s A'),
                'time_between_status' => $this->secondsToTime($previous_log->created_at->diffInSeconds($log->created_at)),
                'processing_time' => $this->secondsToTime($new_order->created_at->diffInSeconds($log->created_at)),
                'key_time' => $key_time ? $this->secondsToTime($key_time) : null,
                'km_between' => $km_between ? number_format($km_between, 2) : null,
                'note' => $log->note(),
                'canceled_by' => $log->canceled_by ? (array_key_exists($log->canceled_by, \App\OrderLog::CANCELED_BY) ? \App\OrderLog::CANCELED_BY[$log->canceled_by] : null) : null,
                'repeated_logs' => isset($status_repeated_logs[$log->status_id]) ? $status_repeated_logs[$log->status_id] : null
            ];
            
            // Reset for next iteration
            if (isset($status_repeated_logs[$log->status_id])) {
                $status_repeated_logs[$log->status_id] = [];
            }
            $key_time = null;
        }
        
        return $logs;
    }
    
    private function formatSingleLog($log)
    {
        return [
            'id' => $log->id,
            'status' => $log->progress ? $log->progress->name : 'N/A',
            'status_id' => $log->status_id,
            'note' => $log->note(),
            'created_by' => isset($log->createdBy) ? $log->createdBy->name : 'System',
            'date' => $log->created_at->format('Y-m-d'),
            'time' => $log->created_at->format('h:i:s A'),
        ];
    }
    
    private function formatOrderNotes($order)
    {
        $notes = [];
        
        if ($order->note) {
            $notes[] = [
                'note' => $order->note,
                'created_by' => null,
                'created_at' => null
            ];
        }
        
        foreach ($order->notes as $note) {
            $notes[] = [
                'note' => $note->note,
                'created_by' => isset($note->user) ? $note->user->name : 'System',
                'client_info' => isset($note->user->employeeClient[0]) 
                    ? '(' . $note->user->employeeClient[0]->user->name . ')'
                    : '(4u Logistices)',
                'created_at' => isset($note->created_at) ? $note->created_at->format('Y-m-d h:i:s A') : null
            ];
        }
        
        return $notes;
    }
    
    private function formatTicket($ticket)
    {
        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'type' => $ticket->type,
            'created_at' => $ticket->created_at->format('Y-m-d H:i:s'),
            'closed_at' => $ticket->closed_at ? $ticket->closed_at->format('Y-m-d H:i:s') : null,
            'messages' => $ticket->messages->map(function($message) {
                return [
                    'id' => $message->id,
                    'message' => $message->message,
                    'created_at' => $message->created_at->format('d-m-Y h:i:s a'),
                    'sender' => [
                        'id' => $message->sender->id,
                        'name' => $message->sender->name,
                        'is_captain' => !!$message->captain,
                        'is_client' => $message->sender->client ? true : false
                    ]
                ];
            })
        ];
    }
    
    private function secondsToTime($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds - ($hours * 3600)) / 60);
        $secs = $seconds % 60;
        
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
}