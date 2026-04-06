<?php

namespace App\Services\ThirdPartyLogistic;

use App\Interfaces\ThirdPartyLogisticInterface;

class CompanyService
{
    public function __construct(private readonly ThirdPartyLogisticInterface $interface) {}
   
    public function CompanyEarning3PL($companyId,$perPage,$filters)
    {
        return $this->interface->companyEarningList($companyId,$perPage,$filters);
    }
   
}