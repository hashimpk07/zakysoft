<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ClientExport implements FromCollection, WithHeadings
{
    use Exportable;
    private $clients = [];
    public function __construct($clients)
    {
        $this->clients = $clients;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        return $this->clients;
    }

    public function headings(): array
    {
        return ["ID", "Client Name", "Email", "Mobile No", "Region", "Area", 'Platform', "Status"];
    }
}
