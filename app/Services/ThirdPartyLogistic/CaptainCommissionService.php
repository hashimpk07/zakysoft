<?php
namespace App\Services\ThirdPartyLogistic;

use App\Captain;
use App\Interfaces\ThirdPartyLogisticInterface;


class CaptainCommissionService 
{
    protected $repository;

    public function __construct(ThirdPartyLogisticInterface $repository)
    {
        $this->repository = $repository;
    }

    public function createCaptainCommission(Captain $captain, array $data)
    {
        return $this->repository->createCaptainCommission($captain, $data);
    }

    public function createCaptainCommissionConfirmationPayment(array $data)
    {
        return $this->repository->createCaptainCommissionConfirmationPayment( $data);
    }
}
