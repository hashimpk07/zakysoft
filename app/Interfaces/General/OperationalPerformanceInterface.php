<?php

namespace App\Interfaces\General;

use App\Services\General\DTO\OperationalDateDTO;

interface OperationalPerformanceInterface
{
    public function getTotalOrderCounts(OperationalDateDTO $dto): int;
    public function getTotalDeliveryCount(OperationalDateDTO $dto): int;
    public function getTotalFailedCount(OperationalDateDTO $dto): int;
    public function getTotalMonthlyTillDate(OperationalDateDTO $dto): int;
    public function getTotalOrdersYTD(OperationalDateDTO $dto): int;
    public function getClientCount(OperationalDateDTO $dto): int;
    public function getBranchCount(OperationalDateDTO $dto): int;
    public function getServingRegions(): int;
    public function getThirdPartyCompaniesCount(): int;
    public function getTotalCaptains(): int;
    public function getTotalDeliveredCaptains(OperationalDateDTO $dto): int;
}