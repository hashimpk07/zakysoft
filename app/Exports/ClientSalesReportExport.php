<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;

class ClientSalesReportExport implements FromView
{
    use Exportable;

    public $orders;

    public function __construct($orders)
    {
        $this->orders = $orders; 
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function view(): View
    {
        return view('orders.client-exports', ['orders' => $this->orders, 'dont_show_client' => true ]);
    }
}
