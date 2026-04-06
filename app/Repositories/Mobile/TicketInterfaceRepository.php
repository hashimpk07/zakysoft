<?php

namespace App\Repositories\Mobile;

use App\Captain;
use App\Interfaces\Mobile\TicketInterface as MobileTicketInterface;
use App\Ticket;
use App\TicketReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class TicketInterfaceRepository implements MobileTicketInterface
{
    public function getCaptainTickets(Captain $captain, Request $request)
    {
        return Ticket::query()
            ->with('client:user_id', 'client.user:id,name')
            ->withCount(['notCaptainSeenMessages as not_seen_messages_count'])
            ->where('captain_id', $captain->id)
            ->where('type', $request->data['type'] ?? Ticket::TYPE_TICKET)
            ->when($request->data['closed'] ?? false, function ($query) {
                $query->whereNotNull('closed_at');
            })
            ->when(!($request->data['closed'] ?? false), function ($query) {
                $query->whereNull('closed_at');
            })
            ->latest()
            ->get();
    }

    public function createTicket(array $data): Model|Ticket
    {
        return Ticket::create($data);
    }

    public function hasOpenTicketByOrder(int $orderId): bool
    {
        return Ticket::where('order_id', $orderId)->where('type', 1)->whereNull('closed_at')->exists();
    }

    public function getTicketReason(int $reasonId): ?string
    {
        return TicketReason::where('id', $reasonId)->value('reason');
    }

    public function findCaptainTicket(int $ticketId, int $captainId): ?Ticket
    {
        return Ticket::query()->where('captain_id', $captainId)->findOrFail($ticketId);
    }

    public function updateNotCaptainSeenMessages(Ticket $ticket): bool
    {
        return $ticket->notCaptainSeenMessages()->update([
            'seen_at' => now(),
        ]);
    }

    public function findOpenTicket(int $ticketId)
    {
        return Ticket::where('id', $ticketId)->whereNull('closed_at')->firstOrFail();
    }

    public function createTicketMessage(Ticket $ticket, array $data)
    {
        return $ticket->messages()->create($data);
    }
}
