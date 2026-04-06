<?php

namespace App\Services\General\Reports\ClientReports;

use App\Interfaces\General\ClientReports\ClientTransactionsInterface;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;


final class ClientTransactionsService
{
   
    public function __construct(protected readonly ClientTransactionsInterface $interface) {}

    public function getClientTransactionsReport(array $data, int $perPage )
    {
        $dates = $this->calculateBusinessDate($data);

        $filters = [
            'client' => $data['client'] ?? null,
            'captain' => $data['captain'] ?? null,
            'from' => $dates['from'],
            'to' => $dates['to'],
            'per_page' => $data['per_page'] ?? 15
        ];
        $transactions = $this->interface->getTransactions($filters,$perPage);

        $paid_in = 0;
        $paid_out = 0;

        foreach ($transactions as $transaction) {
            if ($transaction->status == 'Received') {
                $paid_in += (float) $transaction->receivable_amount;
                $paid_in += (float) $transaction->bank_receivable_amount;
                $paid_out += (float) $transaction->transferred;
            }
        }

        return [
            'transactions' => $transactions,
            'paid_in' => $paid_in,
            'paid_out' => $paid_out
        ];

        //return $this->interface->getTransactions($filters,$perPage);
    }

    private function calculateBusinessDate(array $data)
    {
        if (!empty($data['from_date']) && !empty($data['to_date'])) {

            $from = Carbon::parse($data['from_date'])->setTime(6,0,0);

            $to = Carbon::parse($data['to_date'])
                ->addDay()
                ->setTime(5,59,59);

        } else {

            $from = Carbon::now()
                ->subDays(7)
                ->setTime(6,0,0);

            $to = Carbon::now()
                ->setTime(5,59,59);
        }

        return [
            'from' => $from,
            'to' => $to
        ];
    }

}