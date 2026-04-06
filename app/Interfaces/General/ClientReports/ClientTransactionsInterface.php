<?php

namespace App\Interfaces\General\ClientReports;

use Illuminate\Validation\Rules\In;

interface ClientTransactionsInterface
{
    public function getTransactions(array $filters, int $perPage);
}