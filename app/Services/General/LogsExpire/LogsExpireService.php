<?php
namespace App\Services\General\LogsExpire;

use App\Interfaces\General\LogsExpireInterface;


class LogsExpireService
{
    public function __construct(private readonly LogsExpireInterface $logsExpInterface)
    {
    }

    public function getLogsList(int $perPage) 
    {
        return $this->logsExpInterface->getLogsList($perPage);
    }

    public function getExpireList(array $filters, int $perPage)
    {
        return $this->logsExpInterface->getExpireList($filters, $perPage);
    }

}