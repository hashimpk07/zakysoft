<?php
// app/View/Components/PaginationInfo.php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\Pagination\LengthAwarePaginator;

class PaginationInfo extends Component
{
    public $paginator;

    public function __construct(LengthAwarePaginator $paginator)
    {
        $this->paginator = $paginator;
    }

    public function render()
    {
        return view('components.pagination-info');
    }
}