<?php

namespace App\Services\PublicApi;

use App\Events\TicketUpdated;
use App\Http\Requests\PublicApi\TicketReportFilter;
use App\Interfaces\TicketInterface;
use App\Order;
use App\Ticket;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class TicketService
{
    public function __construct(private readonly TicketInterface $ticketInterface) {}
    public function getUserTickets(Request $request)
    {
        $filters = [
            'search' => $request->search ?? null,
            'type' => $request->type ?? null,
            'open' => $request->open ?? true,
            'close' => $request->closed ?? false,
        ];

        return $this->ticketInterface->list($filters);
    }

    public function getMessageCounts(): array
    {
        $baseQuery = $this->ticketInterface->baseMessageCountQuery();

        return [
            'ticket' => (clone $baseQuery)->where('tickets.type', Ticket::TYPE_TICKET)->get()->sum('not_user_seen_messages_count') ?? 0,

            'pending' => (clone $baseQuery)->where('tickets.type', Ticket::TYPE_PENDING)->get()->sum('not_user_seen_messages_count') ?? 0,

            'client' => (clone $baseQuery)->where('tickets.type', Ticket::TYPE_CLIENT)->get()->sum('not_user_seen_messages_count') ?? 0,
        ];
    }

    public function getTicketById(int $ticket)
    {
        return $this->ticketInterface->findTicket($ticket);
    }

    public function sendMessage(Order $order, string $message, int $userId)
    {
        return DB::transaction(function () use ($order, $message, $userId) {
            $ticket = $this->ticketInterface->getOrCreateOpenComplaint($order, $userId);

            $message = $this->ticketInterface->addMessage($ticket, $message, $userId);

            TicketUpdated::dispatch($ticket);

            return $message->load('sender:id,email,type,name', 'ticket:id');
        });
    }

    public function markMessagesAsSeenByCaptain(Ticket $ticket): int
    {
        return $ticket->notCaptainSeenMessages()->update([
            'seen_at' => now(),
        ]);
    }

    public function getTicketReports(TicketReportFilter $request): LengthAwarePaginator{
        return $this->ticketInterface->getTicketReports($request);
    }
}
