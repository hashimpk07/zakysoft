<?php

namespace App\Interfaces\General;

use App\Designation;
use App\DesignationType;
use Illuminate\Http\Request;

interface DesignationInterface{
    public function getDesignationType(Request $request);
    public function create(array $data): Designation;
    public function update(Designation $designation, array $data): Designation;

    public function createDesignationType(array $data): DesignationType; 
  
}