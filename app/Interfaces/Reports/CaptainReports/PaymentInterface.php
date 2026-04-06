<?php

namespace App\Interfaces\Reports\CaptainReports;

use Illuminate\Pagination\LengthAwarePaginator;

interface PaymentInterface
{
    public function getCommissionPaymentReport(array $filters): LengthAwarePaginator;

    public function getCaptains();

    public function getSettledByUsers();

    public function getSalaryPayments(array $filters): LengthAwarePaginator;
    public function getSalarySettledByUsers();

}