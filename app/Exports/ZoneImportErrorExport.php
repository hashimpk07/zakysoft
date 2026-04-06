<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ZoneImportErrorExport implements FromCollection, WithHeadings
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
        return $this->errors->map(function ($error) {
            return [
                'country_iso_code' => $error['country_iso_code'],
                'region' => $error['region'],
                'area' => $error['area'],
                'zones' => $error['zones'],
                'tire' => $error['tire'],
                'error' => $error['errors'],
            ];
        });
    }

    public function headings(): array
    {
        return ["Country (ISO code)", "Region", "Area", "Zones", "Tire", "Error"];
    }
}
