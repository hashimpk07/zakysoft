<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class ManagementDashboardButtons extends Component
{
    public string $active;

    /**
     * Create a new component instance.
     */
    public function __construct(string $active = 'Orders')
    {
        $this->active = $active;
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.management-dashboard-buttons');
    }
}
