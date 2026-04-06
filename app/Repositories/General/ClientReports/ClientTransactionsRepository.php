<?php

namespace App\Repositories\General\ClientReports;

use App\Interfaces\General\ClientReports\ClientTransactionsInterface;

use App\Transaction;

class ClientTransactionsRepository implements ClientTransactionsInterface
{
    public function getTransactions(array $filters, int $perPage)
    {
       $query = Transaction::belongsToMe()
        ->with([
            'client.user',
            'captain.user',
            'user',
            'statusBy'
        ])
        ->has('client')
        ->orderByDesc('id');

        if (!empty($filters['client'])) {
            $query->where('client_id', $filters['client']);
        }

        if (!empty($filters['captain'])) {
            $query->where('captain_id', $filters['captain']);
        }

        if (!empty($filters['from']) && !empty($filters['to'])) {
            $query->whereBetween('created_at', [$filters['from'], $filters['to']]);
        }

        return $query->paginate($perPage);
    }

}