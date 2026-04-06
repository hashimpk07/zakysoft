<?php

namespace App\Interfaces\General;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;


interface CaptainTransactionsReportsInterface
{
    public function getTransactions($filters,$perPage);
    public function getExpenseTransactions($filters,$perPage);
}