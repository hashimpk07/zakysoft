<?php

namespace App\Exports\Exportable;

class ToPDF implements Exportable {
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
        return $this->export->stream();
    }
    
    public function save($path)
    {
        return $this->export->save($path);
    }
}
