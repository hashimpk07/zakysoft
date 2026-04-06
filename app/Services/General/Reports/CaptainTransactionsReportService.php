<?php

namespace App\Services\General\Reports;

use App\Interfaces\General\CaptainTransactionsReportsInterface;


final class CaptainTransactionsReportService
{
   
    public function __construct(protected readonly CaptainTransactionsReportsInterface $interface) {}

    public function getCaptainTransactionReportsList($request, int $perPage)
    {
        $filters = [
            'captain' => $request->captain,
            'from_date' => $request->from_date,
            'to_date' => $request->to_date
        ];

        $transactions = $this->interface->getTransactions($filters,$perPage);
        $expenseTransactions = $this->interface->getExpenseTransactions($filters,$perPage);

        if($request->report == 2){
            $transactions = $expenseTransactions;
        }

        return $transactions;

    }
}
