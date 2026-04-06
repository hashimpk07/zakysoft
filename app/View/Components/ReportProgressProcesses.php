<?php

namespace App\View\Components;

use App\GeneralExport;
use Illuminate\View\Component;

class ReportProgressProcesses extends Component
{
    public $processes;

    public function __construct()
    {
      $this->processes = GeneralExport::processing()->belongsToMe()->get();
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return \Illuminate\View\View|string
     */
    public function render()
    {
        return view('components.report-progress-processes');
    }
}