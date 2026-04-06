<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CaptainDeliveryViewReportExport implements FromCollection, WithHeadings
{
    use Exportable;
    private $orders = [];
    public function __construct($orders)
    {
        $this->orders = $orders;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        return $this->orders;
    }

    public function headings(): array
    {
        return ["Order Date", "Captain", "Client", "Shop", "AWB", "Delivered Date", "Order Status", "Payment Mode", "Bill Amount", "Store Payments", "Credited To Leajlak (SPAN)", "COD", "Paid Amount", "Received Amount", "Balance", "Done By"];
    }
}
