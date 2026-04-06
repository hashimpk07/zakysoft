<?php

namespace App\Interfaces;

use App\Http\Requests\PublicApi\TicketReportFilter;
use App\Order;
use App\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

interface TicketInterface
{
    public function handleOrderCloseTicket(Order $order, int $statusId);
    public function list(array $filters = []);
    public function findTicket(int $id);
    public function baseMessageCountQuery();
    public function getOrCreateOpenComplaint(Order $order, int $userId): Ticket;
    public function addMessage(Ticket $ticket, string $message, int $userId);
    public function getTicketReports(TicketReportFilter $request): LengthAwarePaginator;
}
