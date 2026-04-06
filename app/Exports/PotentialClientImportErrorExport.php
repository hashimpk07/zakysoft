<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PotentialClientImportErrorExport implements FromCollection, WithHeadings
{
    private $errors = [];
    public function __construct($errors)
    {
        $this->errors = $errors;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        return $this->errors;
    }

    public function headings(): array
    {
        return ["Client Name","Coordinates","POC Name","POC Position","POC mobile","POC Land Line","Email","Website","Industry Type","Expected Order Range/Day", "Error"];
    }
}
