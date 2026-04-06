<?php

namespace App\View\Components;

use Illuminate\View\Component;
use NumberFormatter;

class SpellOut extends Component
{

    public $spell_out;
    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct($number)
    {
        $f = new \NumberFormatter("en", \NumberFormatter::SPELLOUT);
        $this->spell_out = $f->format($number);
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return \Illuminate\View\View|string
     */
    public function render()
    {

        return <<<'blade'
                {{ strtoupper($spell_out) }} ONLY
            blade;
    }
}
