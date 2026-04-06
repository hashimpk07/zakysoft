<?php

namespace App\Exports;

use App\Order;
use App\Ticket;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;

class TicketExport implements FromView
{
    use Exportable;

    public $tickets;
    public function __construct($tickets)
    {
        $this->tickets = $tickets;
    }
    /**
     * @return \Illuminate\Support\Collection
     */
    public function view(): View
    {
        return view('ticket.export', ['tickets' => $this->tickets]);
    }
}
