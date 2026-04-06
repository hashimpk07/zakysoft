<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\Exportable;

class ThirdPartyCommissionViewReportExport implements FromCollection, WithHeadings
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
        return ["Order Date","Order Number","Client Name","Shop Name","AWB","Dist.b/w Shop & Dlvry","Extra KM","Delivered Date","Order Status","Captain Name","Captain	Iqama No","B.D Earning","E.KM. Earning","T. Earning","Sub total","Payments"];
    }
}
