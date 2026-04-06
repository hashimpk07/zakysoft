<?php

namespace App\Repositories\General;
use App\Interfaces\General\CaptainTransactionsReportsInterface;
use App\Captain;
use App\Transaction;
use App\ExpenseTransaction;

use Illuminate\Support\Collection;

class CaptainTransactionsReportsInterfaceRepository implements CaptainTransactionsReportsInterface
{

    public function getTransactions($filters,$perPage)
    {
        $query = Transaction::with('captain','user','statusBy')
            ->has('captain')
            ->orderBy('id','desc');

        if(!empty($filters['captain'])) {
            $query->where('captain_id',$filters['captain']);
        }

        if(!empty($filters['from_date'])) {
            $query->where('created_at','>=',$filters['from_date'].' 06:00:00');
        }

        if(!empty($filters['to_date'])) {
            $query->where('created_at','<=',$filters['to_date'].' 05:59:59');
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function getExpenseTransactions($filters,$perPage)
    {
        $query = ExpenseTransaction::with('captain','user','statusBy')
            ->orderBy('id','desc');

        if(!empty($filters['captain'])) {
            $query->where('captain_id',$filters['captain']);
        }

        if(!empty($filters['from_date'])) {
            $query->where('created_at','>=',$filters['from_date'].' 06:00:00');
        }

        if(!empty($filters['to_date'])) {
            $query->where('created_at','<=',$filters['to_date'].' 05:59:59');
        }

        return $query->paginate($perPage)->withQueryString();
    }

}