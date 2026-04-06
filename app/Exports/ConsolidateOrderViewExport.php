<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ConsolidateOrderViewExport implements FromCollection, WithHeadings
{
    use Exportable;
    private $shops = [];
    public function __construct($shops)
    {
        $this->shops = $shops;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        return $this->shops;
    }

    public function headings(): array
    {
        return ["Branch Name", "Client Order Id", "Created Date And Time", "Order Status", "Payment Mode", "End Time"];
    }
}
