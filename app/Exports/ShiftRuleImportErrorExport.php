<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ShiftRuleImportErrorExport implements FromCollection, WithHeadings
{
    private $errors = [];
    public function __construct($errors)
    {
        // dd($errors);
        $this->errors = $errors;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        return collect($this->errors)->map(function ($error, $index) {
            if (is_array($error)) {
                return $error;
            }
            return ['row' => $index + 1, 'errors' => $error];
        });
    
    }

    public function headings(): array
    {
        return ["Days", "Shift A Start", "Shift A End", "Shift B Start", "Shift B End", "Error"];
    }
}

