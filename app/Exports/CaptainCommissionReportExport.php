<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CaptainCommissionReportExport implements FromCollection, WithHeadings
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
        return ["Emp ID", "Captain Name", "Job Type", "Iqama Number", "Nationality", "Work Region", "Work Area", "On Duty From", "Work Status", "Attended Orders", "Com/Order", "Total Com", "Paid Com", "Payable Com", "Payment Status"];
    }
}
