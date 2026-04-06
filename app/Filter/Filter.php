<?php

namespace App\Filter;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

abstract class Filter
{
    protected $request;
    protected $builder;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function apply(Builder $builder,$always_calls = [], $not_to_calls = [])
    {
        $this->builder = $builder;
        foreach($always_calls as $name => $value) {
            if(method_exists($this, $name ) && !in_array($name, $not_to_calls)) {
                call_user_func_array([$this, $name], array_filter([$value]));
            }
        }

        foreach($this->filters() as  $name => $value) {
            if(method_exists($this, $name ) && !in_array($name, $not_to_calls)) {
                call_user_func_array([$this, $name], array_filter([$value]));
            }
        }

        return $this->builder;
    }

    public function filters()
    {
        return $this->request->all();
    }

    public function query()
    {
        $query_array = $this->request->all();
        unset($query_array['page']);
        return $query_array;
    }

    public function request()
    {
        return $this->request;
    }
}