<?php

namespace App\Traits;

trait Filter {
    public function scopeFilter($query, $filters, $always_calls = [], $not_to_calls = [])
    {
        return $filters->apply($query, $always_calls, $not_to_calls);
    }
}