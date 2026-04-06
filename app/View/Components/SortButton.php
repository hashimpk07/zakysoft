<?php

namespace App\View\Components;

use Illuminate\View\Component;
use NumberFormatter;

class SortButton extends Component
{
    public $url;
    public $order = null;
    public function __construct(public $field = null, public $key= 'sort',$route = null)
    {
        if(!$route) {
            $route = request()->route()->getName();
        }

        $this->url = route($route, array_merge(request()->all(), [$key .'_by' => $field, $key. '_order' => (request( $key. '_by') == $field && strtolower(request($key.'_order')) == 'asc' ? "desc" : "asc")]));

        if(request($key.'_by') == $field) {
            $this->order = request($key.'_order');
        }
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return \Illuminate\View\View|string
     */
    public function render()
    {
        return view('components.sort-button');
    }
}