<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CaptainCommissionViewReportExport implements FromCollection, WithHeadings
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
        return ["Order Date", "Captain", "Client", "Shop", "AWB", "Delivered Date", "Order Status", "KM", "B.D. Earing", "E.KM. Earing", "T. Earing", "Paid Com", "Paid Date & Time", "Paid By", "Payment Status", "Balance"];
    }
}
