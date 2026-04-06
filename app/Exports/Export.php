<?php 

namespace App\Exports;

use App\Exports\Exportable\ToCSV;
use App\Exports\Exportable\ToPDF;
use Barryvdh\DomPDF\Facade\Pdf as PDF;

class Export {
    
    protected $exports;
    protected $file_name; 

    public function __construct($exports) {
        $this->exports = $exports;
    }

    public function toPdf()
    {
        return (new ToPDF(PDF::setOptions(['logOutputFile'=> storage_path('logs/pdf.log'), 'tempDir'=> storage_path('logs/')])->loadHTML($this->exports->view())));
    }

    public function toCsv()
    {
        return (new ToCSV($this->exports));
    }

    public function to(String $to)
    {
        return $this->{$to}();
    }
}