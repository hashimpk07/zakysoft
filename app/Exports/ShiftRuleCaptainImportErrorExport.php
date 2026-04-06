<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ShiftRuleCaptainImportErrorExport implements FromCollection, WithHeadings
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
            // dd($error);
            return [
                'captain_id' => $error['captain_id'],
                'name' => $error['name'],
                'iqama_no' => $error['iqama_no'],
                'error' => $error['errors'],
            ];
        });
    }

    public function headings(): array
    {
        return ["Captain Id", "Name", "Iqama No", "Error"];
    }
}
