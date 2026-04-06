<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\Transaction;
use App\ExpenseTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CaptainTransactionExportJob extends QueueExport
{
    protected int $chunk = 1000;
    protected string $file_name = 'Captain Transaction Report';

    public $timeout = 100000;

    public function data(): array
    {
        $data = [];
        $transactions = $this->getReport();

        foreach ($transactions as $transaction) {

            /** Payment Status */
            if ($transaction->transferred != null && $transaction->status == 'Received') {
                $transaction_status = 'Accepted';
            } else {
                $transaction_status = $transaction->status;
            }

            /** Entry Name */
            if ($transaction->receivable_amount != null || $transaction->bank_receivable_amount != null) {
                $entry_name = 'Paid In';
            } else {
                $entry_name = '4U Paid Out';
            }

            /** Payer */
            if ($transaction->transferred) {
                $payer = isset($transaction->client->user->name)
                    ? $transaction->client->user->name
                    : ($transaction->captain->user->name ?? '');
            } else {
                $payer = $transaction->statusBy->name ?? '';
            }

            $data[] = [
                'date' => $transaction->created_at->format('d/m/Y'),
                'time' => $transaction->created_at->format('H:i:s'),
                'entry_name' => $entry_name,
                'payable' => $transaction->payable ?? 0,
                'transferred' => $transaction->transferred ?? 0,
                'receivable_cash' => $transaction->receivable_amount ?? 0,
                'receivable_bank' => $transaction->bank_receivable_amount ?? 0,
                'balance' => $transaction->balance ?? 0,
                'payment_method' => $transaction->payment_mode ?? 'N/A',
                'payment_status' => $transaction_status,
                'payee' => $transaction->user->name ?? '',
                'payer' => $payer,
            ];
        }

        Log::info('CaptainTransactionExportJob chunk processed', [
            'page_done' => $this->export->page_done,
            'rows' => count($data),
        ]);

        return $data;
    }

    protected function getReport()
    {
        $filters = $this->export->filters ?? [];
        $captain = $filters['captain'] ?? null;
        $fromDate = $filters['from_date'] ?? null;
        $toDate = $filters['to_date'] ?? null;
        $report = $filters['report'] ?? 1;

        $offset = ($this->export->page_done ?? 0) * $this->chunk;

        if ($report == 2) {
            $query = ExpenseTransaction::with('captain.user', 'user', 'statusBy', 'client.user')
                ->orderByDesc('id');
        } else {
            $query = Transaction::with('captain.user', 'user', 'statusBy', 'client.user')
                ->has('captain')
                ->orderByDesc('id');
        }

        /** Captain filter */
        if ($captain) {
            $query->where('captain_id', $captain);
        }

        /** Date filters */
        if ($fromDate && !$toDate) {
            $query->where('created_at', '>=', Carbon::parse($fromDate)->format('Y-m-d') . ' 06:00:00');
        }

        if ($toDate && !$fromDate) {
            $query->where('created_at', '<=', Carbon::parse($toDate)->format('Y-m-d') . ' 05:59:59');
        }

        if ($fromDate && $toDate) {
            $query->where('created_at', '>=', Carbon::parse($fromDate)->format('Y-m-d') . ' 06:00:00')
                  ->where('created_at', '<=', Carbon::parse($toDate)->format('Y-m-d') . ' 05:59:59');
        }

        return $query
            ->limit($this->chunk)
            ->offset($offset)
            ->get();
    }

    public function count(): int
    {
        $filters = $this->export->filters ?? [];
        $captain = $filters['captain'] ?? null;
        $fromDate = $filters['from_date'] ?? null;
        $toDate = $filters['to_date'] ?? null;
        $report = $filters['report'] ?? 1;

        $query = $report == 2
            ? ExpenseTransaction::query()
            : Transaction::query()->has('captain');

        if ($captain) {
            $query->where('captain_id', $captain);
        }

        if ($fromDate && !$toDate) {
            $query->where('created_at', '>=', Carbon::parse($fromDate)->format('Y-m-d') . ' 06:00:00');
        }

        if ($toDate && !$fromDate) {
            $query->where('created_at', '<=', Carbon::parse($toDate)->format('Y-m-d') . ' 05:59:59');
        }

        if ($fromDate && $toDate) {
            $query->where('created_at', '>=', Carbon::parse($fromDate)->format('Y-m-d') . ' 06:00:00')
                  ->where('created_at', '<=', Carbon::parse($toDate)->format('Y-m-d') . ' 05:59:59');
        }

        return $query->count();
    }

    public function headers(): array
    {
        return [
            'Date',
            'Time',
            'Entry Name',
            'Payable',
            'Transferred',
            'Receivable By Cash',
            'Receivable By Bank',
            'Balance',
            'Payment Method',
            'Payment Status',
            'Payee',
            'Payer',
           
        ];
    }
}