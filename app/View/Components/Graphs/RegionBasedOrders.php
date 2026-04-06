<?php

namespace App\View\Components\Graphs;

use App\Quadrant;
use Illuminate\View\Component;

class RegionBasedOrders extends Component
{
    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct()
    { }

    /**
     * Get the view / contents that represent the component.
     *
     * @return \Illuminate\View\View|string
     */
    public function render()
    {
        $quadrants = Quadrant::with('regions')->get();

        return view('components.graphs.region_based_orders', ['quadrants' => $quadrants]);
    }
}
