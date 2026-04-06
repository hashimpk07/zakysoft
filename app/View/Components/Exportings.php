<?php

namespace App\View\Components;

use App\Export;
use Illuminate\Support\Facades\Bus;
use Illuminate\View\Component;

class Exportings extends Component
{
    public $exports = null;

    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct($exports)
    {
        $this->exports = Export::belongsToMe()
                                ->where('export_type', $exports)
                                ->get()->map(function ($export) {
                                    $export->setRelation('batch', Bus::findBatch($export->batch_id));

                                    return $export;
                                });
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return \Illuminate\Contracts\View\View|\Closure|string
     */
    public function render()
    {
        return view('components.exportings');
    }
}
