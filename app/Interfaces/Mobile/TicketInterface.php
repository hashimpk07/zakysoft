<?php

namespace App\Interfaces\Mobile;

use App\Captain;
use App\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

interface TicketInterface
{
    public function getCaptainTickets(Captain $captain, Request $request);

    public function createTicket(array $data): Model|Ticket;

    public function hasOpenTicketByOrder(int $orderId):bool;

    public function getTicketReason(int $reasonId): ?string;

    public function findCaptainTicket(int $ticketId, int $captainId): ?Ticket;

    public function updateNotCaptainSeenMessages(Ticket $ticket): bool;

     public function findOpenTicket(int $ticketId);

     public function createTicketMessage(Ticket $ticket, array $data);
}
