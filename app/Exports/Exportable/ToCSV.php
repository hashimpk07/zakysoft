<?php

namespace App\Exports\Exportable;

class ToCSV implements Exportable {
    protected $export;

    public function __construct($export)
    {
        $this->export = $export; 
    }

    public function download($name)
    {
        return $this->export->download($name);
    }

    public function stream($name)
    {
        return $this->download($name);
    }
    
    public function save($path)
    {
        return $this->export->store($path);
    }
}
