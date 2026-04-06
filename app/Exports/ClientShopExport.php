<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ClientShopExport implements FromCollection, WithHeadings
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
        return ["Date", "Shop Id", "Name", "Location", "Dispatch Rule(express)","Applied Price Rule","Created By", 'Status', "Zones"];
    }
}
