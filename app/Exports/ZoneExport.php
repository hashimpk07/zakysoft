<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ZoneExport implements FromCollection, WithHeadings
{
    use Exportable;
    private $zones = [];
    public function __construct($zones)
    {
        $this->zones = $zones;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        return $this->zones;
    }

    public function headings(): array
    {
        return ["Id","Country (ISO code)", "Region", "Area",'Tire', "Zones",'Status'];
    }
}
