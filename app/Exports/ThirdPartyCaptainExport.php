<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ThirdPartyCaptainExport implements FromCollection, WithHeadings
{
    use Exportable;
    private $captains = [];
    public function __construct($captains)
    {
        $this->captains = $captains;
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return $this->captains;
    }

    public function headings(): array
    {
        return ["Captain Id", "Name", "Mobile Number", "Total Delivery", "Region", "Area", "Status", "Shift Status", "Vehicle", "App Current Version"];
    }
}
