<?php
namespace App\Repositories;

use App\Events\TicketClosed;
use App\Http\Requests\PublicApi\TicketReportFilter;
use App\Interfaces\TicketInterface;
use App\Order;
use App\OrderStatus;
use App\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class TicketInterfaceRepository implements TicketInterface
{
    public function handleOrderCloseTicket(Order $order, int $statusId)
    {
        $tickets = $order->openTickets;

        if (! $tickets || in_array($statusId, [OrderStatus::TICKET_RAISED, OrderStatus::PENDING])) {
            return;
        }

        foreach ($tickets as $ticket) {
            if ($ticket->type != Ticket::TYPE_CLIENT || ($ticket->type == Ticket::TYPE_CLIENT && in_array($statusId, [OrderStatus::DELIVERED, OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED]))) {
                $ticket->update(['closed_at' => now()]);
                TicketClosed::dispatch($ticket);
            }
        }
    }

    public function list(array $filters = [])
    {
        return Ticket::query()
            ->belongsToMe()
            ->with(['captain', 'order.shop', 'takenByUser', 'client.user'])
            ->withCount(['notUserSeenMessages as not_seen_messages_count'])
            ->when($filters['q'] ?? null, fn($q, $term) => $q->whereLike(['order_id', 'id', 'order.client_order_id'], $term))
            ->when($filters['type'] ?? null, fn($q, $type) => $q->where('tickets.type', $type))
            ->when($filters['open'] ?? true, fn($q) => $q->whereNull('closed_at'))
            ->when($filters['closed'] ?? false, fn($q) => $q->whereNotNull('closed_at'))
            ->latest()
            ->get();
    }

    public function findTicket(int $id)
    {
        return Ticket::query()
            ->belongsToMe()
            ->with(['client.user', 'messages.captain', 'messages.sender.captain', 'messages.sender.client', 'order.captain', 'order.client.user', 'order.progress', 'order.zone', 'order.region', 'order.shop', 'order.payment', 'captain', 'takenByUser'])
            ->whereNull('closed_at')
            ->findOrFail($id);
    }

    public function baseMessageCountQuery()
    {
        return Ticket::query()->belongsToMe()->open()->withCount('notUserSeenMessages');
    }

    public function getOrCreateOpenComplaint(Order $order, int $userId): Ticket
    {
        return $order->openComplaint ?? Ticket::create([
            'subject'     => null,
            'order_id'    => $order->id,
            'captain_id'  => null,
            'type'        => Ticket::TYPE_CLIENT,
            'created_by'  => $userId,
        ]);
    }

    public function addMessage(Ticket $ticket, string $message, int $userId): Model
    {
        return $ticket->messages()->create([
            'message'   => $message,
            'sender_id' => $userId,
        ]);
    }

     public function getTicketReports(TicketReportFilter $request): LengthAwarePaginator
    {
        return Ticket::query()
            ->belongsToMe()
            ->when($request->status(),    fn($q) => $this->applyStatusFilter($q, $request->status()))
            ->when($request->clientId(),  fn($q) => $this->applyClientFilter($q, $request->clientId()))
            ->when($request->captainId(), fn($q) => $q->where('tickets.captain_id', $request->captainId()))
            ->when($request->type(),      fn($q) => $q->where('tickets.type', $request->type()))
            ->when(
                $request->fromDate(),
                fn($q) => $this->applyDateRangeFilter($q, $request->fromDate(), $request->toDate()),
                fn($q) => $this->applyCurrentBusinessDayFilter($q),
            )
            ->with($this->eagerLoadRelations())
            ->latest('tickets.created_at')
            ->paginate($request->perPage())
            ->withQueryString();
    }
 
    // -------------------------------------------------------------------------
    // Private filter methods
    // -------------------------------------------------------------------------
 
    private function applyStatusFilter(Builder $query, string $status): Builder
    {
        return match ($status) {
            'closed' => $query->whereNotNull('closed_at'),
            'open'   => $query->whereNull('closed_at'),
            'missed' => $this->applyMissedStatusFilter($query),
            default  => $query,
        };
    }
 
    private function applyMissedStatusFilter(Builder $query): Builder
    {
        return $query
            ->whereNull('taken_by')
            ->whereExists(fn($sub) => $sub
                ->selectRaw(1)
                ->from('messages')
                ->whereRaw('messages.ticket_id = tickets.id')
                ->where('sender_id', config('app.bot_user'))
            )
            ->whereNotExists(fn($sub) => $sub
                ->selectRaw(1)
                ->from('messages')
                ->join('users', 'messages.sender_id', '=', 'users.id')
                ->leftJoin('captains', 'users.id', '=', 'captains.user_id')
                ->leftJoin('clients', 'users.id', '=', 'clients.user_id')
                ->whereRaw('messages.ticket_id = tickets.id')
                ->where('messages.sender_id', '!=', config('app.bot_user'))
                ->whereNull('captains.id')
                ->whereNull('clients.id')
            );
    }
 
    private function applyClientFilter(Builder $query, int $clientId): Builder
    {
        return $query->whereHas('order', fn($q) => $q->where('client_id', $clientId));
    }
 
    private function applyDateRangeFilter(Builder $query, string $fromDate, ?string $toDate): Builder
    {
        [$start, $end] = getSystemTimeRange($fromDate, $toDate ?? $fromDate);
 
        return $query
            ->whereNotNull('tickets.order_id')
            ->where('tickets.created_at', '>=', $start)
            ->where('tickets.created_at', '<=', $end);
    }
 
    private function applyCurrentBusinessDayFilter(Builder $query): Builder
    {
        [$start, $end] = getSystemTimeRange(null, null);
 
        return $query
            ->where('tickets.created_at', '>=', $start)
            ->where('tickets.created_at', '<=', $end);
    }
 
    // -------------------------------------------------------------------------
    // Eager-load relations
    // -------------------------------------------------------------------------
 
    private function eagerLoadRelations(): array
    {
        return [
            'order.captain.user',
            'order.progress',
            'order.payment',
            'order.client.user',
            'order.shop',
            'order'   => fn($q) => $q->withShopRegionAndZone(),
            'captain.user',
            'client.user',
            'messages.sender.captain',
            'takenByUser',
        ];
    }
}
