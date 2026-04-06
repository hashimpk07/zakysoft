<?php

namespace App\Services\Mobile;

use App\Captain;
use App\Events\OrderStatusChanged;
use App\Events\TicketUpdated;
use App\Interfaces\Mobile\TicketInterface as MobileTicketInterface;
use App\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Facades\App\Services\OrderStatusLog;

final class TicketService
{
    public function __construct(protected readonly MobileTicketInterface $mobileTicketInterface, protected readonly GeneralService $generalService)
    {
    }

    public function getAllCaptainTickets(Captain $captain, Request $request)
    {
        return $this->mobileTicketInterface->getCaptainTickets(captain: $captain, request: $request);
    }

    public function createTicket(Captain $captain, Request $request)
    {
        $reasonId = $request->data['id'] ?? null;
        $orderIds = $request->data['order_ids'] ?? [];
        $description = $request->data['descriptions'] ?? '';

        $orderService = app(OrderService::class);
        foreach ($orderIds as $orderId) {
            $order = $this->generalService->findOrderById($orderId);

            if (!$order) {
                continue;
            }
            $isTicketType = in_array($order->status_id, [OrderStatus::REACHED_DESTINATION, OrderStatus::SHIPPED]) ? 2 : 1;
            $statusId = $isTicketType == 2 ? OrderStatus::PENDING : OrderStatus::TICKET_RAISED;

            if ($isTicketType && $this->mobileTicketInterface->hasOpenTicketByOrder($orderId)) {
                throw ValidationException::withMessages([
                    'ticket' => 'This order ' . $orderId . ' has already an open ticket',
                ]);
            }

            $reason = match (true) {
                $reasonId != 0 && $isTicketType == 1 => $this->mobileTicketInterface->getTicketReason($reasonId),
                $reasonId != 0 && $isTicketType == 2 => $this->generalService->getOrderPendingReasonById($reasonId),
                default => $description,
            };

            $ticket = $this->mobileTicketInterface->createTicket(data: ['subject' => $reason, 'order_id' => $orderId, 'captain_id' => $captain->id, 'type' => $isTicketType, 'created_by' => $captain->user->id]);

            $status = $this->generalService->findOrderStatusById($statusId);

            OrderStatusLog::logs('Order', 'Order No ' . $order->client_order_id . ' Status changed from ' . $order->progress->name . ' to ' . $status->name, $captain->user_id);
            $orderService->updateOrder(order: $order, data: ['status_id' => $status->id]);
            if ($status->id == OrderStatus::TICKET_RAISED) {
                OrderStatusLog::log($status->id, $captain->id, $order->id, $reasonId);
            } else {
                OrderStatusLog::log(OrderStatus::TICKET_RAISED, $captain->id, $order->id);
                OrderStatusLog::log($status->id, $captain->id, $order->id, $reasonId);
            }
            OrderStatusChanged::dispatch($order);
        }

        unset($ticket->captain);
        unset($ticket->order);

        return $ticket;
    }

    public function getTicketMessages(Captain $captain, int $tickeId)
    {
        $ticket = $this->findCaptainTicket(captain_id: $captain->id, tickeId: $tickeId);

        if ($ticket->closed_at) {
            throw ValidationException::withMessages([
                'ticket' => 'The ticket is closed. You can\'t open this chat',
            ]);
        }

        $ticket->load('messages.sender:id,name,email', 'messages.sender.captain:user_id,code,phone_number');

        $this->mobileTicketInterface->updateNotCaptainSeenMessages(ticket: $ticket);

        TicketUpdated::dispatch($ticket);

        $ticket = $ticket->fresh();

        $order = $this->generalService->findOrderById($ticket->order_id);

        if ($order) {
            $ticket->order_code = $order->client_order_id;

            foreach ($ticket->messages as $message) {
                $message->isCaptain = $message->sender->captain ? true : false;
            }
        }

        return $ticket;
    }

    public function findCaptainTicket(int $captain_id, int $tickeId)
    {
        return $this->mobileTicketInterface->findCaptainTicket(ticketId: $tickeId, captainId: $captain_id);
    }

    public function createTicketMessage(array $data)
    {

        $ticket = $this->mobileTicketInterface->findOpenTicket($data['ticket_id']);

        $this->mobileTicketInterface->createTicketMessage(ticket: $ticket, data: $data);

        TicketUpdated::dispatch($ticket);

        return true;

    }
}
