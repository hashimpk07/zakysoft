<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CaptainExport implements FromCollection, WithHeadings
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
        return ["Captain Id", "Name", "Iqama No", "Mobile Number", "Total Delivery", "Region", "Area", "Status", "Shift Status", "Vehicle", "Third Party Name", "Job Type", "App Current Version"];
    }
}
